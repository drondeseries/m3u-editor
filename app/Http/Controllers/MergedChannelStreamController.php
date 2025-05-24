<?php

namespace App\Http\Controllers;

use App\Models\MergedChannel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class MergedChannelStreamController extends Controller
{
    public function __invoke(Request $request, $mergedChannelId, $format = 'ts')
    {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4', 'flv'])) { // Added flv as per original controller, though not strictly in problem desc
            abort(400, 'Invalid format specified.');
        }

        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        $mergedChannel = MergedChannel::find($mergedChannelId);

        if (!$mergedChannel) {
            abort(404, 'Merged channel not found.');
        }
        $title = strip_tags($mergedChannel->name);

        // Ownership check: Assuming public access for now, as per initial implementation.
        // If $mergedChannel->user_id needs checking against auth()->id(), add it here.

        $sourceChannels = $mergedChannel->sourceChannels()->get(); // Already ordered by priority

        if ($sourceChannels->isEmpty()) {
            abort(404, 'No source channels found for this merged channel.');
        }

        $streamUrlsWithOriginalChannel = $sourceChannels->mapWithKeys(function ($channel) {
            $url = $channel->url_custom ?: $channel->url;
            return $url ? [$url => $channel] : []; // Map URL to original Channel model for user_agent
        })->filter();


        if ($streamUrlsWithOriginalChannel->isEmpty()) {
            abort(404, 'No stream URLs available for this merged channel.');
        }
        
        // Get user preferences and settings
        $userPreferences = app(GeneralSettings::class);
        $defaultSettings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg', // Default from original controller
            'ffmpeg_reconnect_delay_max' => 5,
            'ffmpeg_stream_idle_timeout' => 30,
        ];

        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $defaultSettings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $defaultSettings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $defaultSettings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $defaultSettings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $defaultSettings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $defaultSettings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $defaultSettings['ffmpeg_path'],
                'ffmpeg_reconnect_delay_max' => $userPreferences->ffmpeg_reconnect_delay_max ?? $defaultSettings['ffmpeg_reconnect_delay_max'],
                'ffmpeg_stream_idle_timeout' => $userPreferences->ffmpeg_stream_idle_timeout ?? $defaultSettings['ffmpeg_stream_idle_timeout'],
            ];
        } catch (\Exception $e) {
            Log::warning("Could not retrieve GeneralSettings for MergedChannelStream: " . $e->getMessage());
            $settings = $defaultSettings;
        }
        
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'ffmpeg'; // Absolute fallback
        }

        $videoCodec = config('proxy.ffmpeg_codec_video') ?: $settings['ffmpeg_codec_video'];
        $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
        $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }
        
        $ip = $request->ip();
        $streamIdBase = Str::random(8); // Base for unique stream ID across different source attempts
        
        $contentType = 'video/MP2T'; // Default for ts
        if ($format === 'mp4') $contentType = 'video/mp4';
        if ($format === 'flv') $contentType = 'video/x-flv';

        return new StreamedResponse(function () use (
            $mergedChannel, $streamUrlsWithOriginalChannel, $title, $settings, $format, $ip, $streamIdBase,
            $ffmpegPath, $videoCodec, $audioCodec, $subtitleCodec, $userArgs, $request
        ) {
            // Make sure PHP doesn't ignore user aborts
            ignore_user_abort(false);

            $app_stream_id_base = "merged_{$mergedChannel->id}_{$format}_{$streamIdBase}";
            $process = null;
            $successfulStream = false;

            // Register a shutdown function that ALWAYS runs when the script dies
            // This is a simplified version, actual ChannelStreamController has more complex Redis cleanup
            register_shutdown_function(function () use (&$process, $app_stream_id_base, $title) {
                if ($process && $process->isRunning()) {
                    $process->stop(0); // Force stop
                }
                Redis::del("stream_stats:details:{$app_stream_id_base}"); // General cleanup for base ID
                Redis::srem("stream_stats:active_ids", $app_stream_id_base);
                Log::channel('ffmpeg')->info("Streaming stopped for MergedChannel {$title} (AppStreamID Base: {$app_stream_id_base})");
            });
            
            // Clear any existing output buffers
            while (ob_get_level()) ob_end_flush();
            flush();
            ini_set('zlib.output_compression', 0);

            foreach ($streamUrlsWithOriginalChannel as $streamUrl => $originalChannel) {
                if ($successfulStream) break;

                $currentSourceChannelId = $originalChannel->id;
                $app_stream_id = "{$app_stream_id_base}_src{$currentSourceChannelId}"; // Unique ID for this specific source attempt

                // User agent: Use playlist specific if available, else from settings
                $userAgent = $originalChannel->playlist && $originalChannel->playlist->user_agent 
                    ? $originalChannel->playlist->user_agent 
                    : $settings['ffmpeg_user_agent'];
                $escapedUserAgent = escapeshellarg($userAgent);

                Log::channel('ffmpeg')->info("Attempting to stream MergedChannel ID: {$mergedChannel->id} (Source Channel ID: {$currentSourceChannelId}, URL: {$streamUrl}) with AppStreamID: {$app_stream_id}");

                // Initial Stream Statistics Data for this attempt
                $rawUserAgentHeader = $request->header('User-Agent') ?? 'Unknown';
                $streamData = [
                    'stream_id' => $app_stream_id,
                    'merged_channel_id' => $mergedChannel->id,
                    'source_channel_id' => $currentSourceChannelId,
                    'channel_title' => "{$title} (Source: {$originalChannel->title})",
                    'client_ip' => $ip,
                    'user_agent_raw_header' => $rawUserAgentHeader,
                    'stream_type' => "MERGED_STREAM",
                    'stream_format_requested' => $format,
                    'video_codec_selected' => $videoCodec,
                    'audio_codec_selected' => $audioCodec,
                    'ffmpeg_pid' => 'N/A',
                    'start_time_unix' => time(),
                    'source_stream_url' => $streamUrl,
                    'ffmpeg_command' => 'pending',
                ];
                Redis::hmset("stream_stats:details:{$app_stream_id}", $streamData);
                Redis::sadd("stream_stats:active_ids", $app_stream_id); // Add specific attempt
                Redis::expire("stream_stats:details:{$app_stream_id}", 3600); // 1 hour expiry

                $hwAccelArgs = ""; // Initialize hwAccelArgs
                $videoFilter = ''; // Ensure videoFilter is initialized
                if (str_contains($videoCodec, 'vaapi')) {
                    $hwAccelArgs = "-hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -hwaccel_output_format vaapi ";
                    $videoFilter = "-vf scale_vaapi=format=nv12 "; // Modified line for $videoFilter
                }

                $outputFormatCmd = '';
                if ($format === 'ts') {
                    $outputFormatCmd = "{$videoFilter}-c:v {$videoCodec} -c:a {$audioCodec} -c:s {$subtitleCodec} -f mpegts pipe:1";
                } elseif ($format === 'mp4') {
                    // Note: VAAPI might not be typically used with MP4 container in this direct way for live streams,
                    // but apply the filter consistently if vaapi is in the codec name.
                    $outputFormatCmd = "{$videoFilter}-c:v {$videoCodec} -c:a {$audioCodec} -bsf:a aac_adtstoasc -c:s {$subtitleCodec} -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
                } elseif ($format === 'flv') {
                    $outputFormatCmd = "{$videoFilter}-c:v {$videoCodec} -c:a {$audioCodec} -c:s {$subtitleCodec} -f flv pipe:1";
                }

                $cmd = sprintf(
                    '%s %s' . // $ffmpegPath, $hwAccelArgs
                    '-user_agent "%s" -referer "%s" ' .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max %d -noautorotate ' .
                    '%s' . // User defined args ($userArgs)
                    '-re -i "%s" ' . // Input
                    '-progress %s ' . // Added for progress reporting
                    '%s ' . // Output format command ($outputFormatCmd)
                    '%s',  // Logging
                    $ffmpegPath,
                    $hwAccelArgs, // NEW
                    $userAgent,
                    $request->headers->get('referer') ?: 'http://localhost/',
                    $settings['ffmpeg_reconnect_delay_max'],
                    $userArgs,
                    $streamUrl,
                    url('/api/stream-progress/' . $app_stream_id), // URL for -progress
                    $outputFormatCmd,
                    $settings['ffmpeg_debug'] ? '' : '-hide_banner -loglevel error' // Removed -nostats
                );
                
                Redis::hset("stream_stats:details:{$app_stream_id}", "ffmpeg_command", $cmd);
                Log::channel('ffmpeg')->info("FFmpeg command for MergedChannel {$mergedChannel->id}, Source {$currentSourceChannelId}: {$cmd}");

                for ($attempt = 1; $attempt <= (int)$settings['ffmpeg_max_tries']; $attempt++) {
                    if (connection_aborted()) {
                         Log::channel('ffmpeg')->info("Connection aborted by client before attempt {$attempt} for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. AppStreamID: {$app_stream_id}");
                         Redis::del("stream_stats:details:{$app_stream_id}");
                         Redis::srem("stream_stats:active_ids", $app_stream_id);
                         return; // Exit StreamedResponse callback
                    }
                    if ($attempt > 1) {
                        Log::channel('ffmpeg')->info("Retrying stream for MergedChannel ID: {$mergedChannel->id}, Source URL: {$streamUrl}, Attempt: {$attempt}/{$settings['ffmpeg_max_tries']}. AppStreamID: {$app_stream_id}");
                        sleep(min(8, $attempt * 2)); // Exponential backoff, max 8s, similar to original controller's sleep logic
                    }

                    if ($process && $process->isRunning()) $process->stop(0);
                    
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);
                    $process->setIdleTimeout($settings['ffmpeg_stream_idle_timeout']);

                    try {
                        $process->start();
                        $pid = $process->getPid();
                        if ($pid) Redis::hset("stream_stats:details:{$app_stream_id}", "ffmpeg_pid", $pid);

                        $iterator = $process->getIterator($process::ITER_SKIP_ERR);
                        foreach ($iterator as $data) {
                            if (connection_aborted()) {
                                Log::channel('ffmpeg')->info("Connection aborted by client during streaming for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. AppStreamID: {$app_stream_id}");
                                if ($process->isRunning()) $process->stop(0, SIGKILL); // Force kill
                                Redis::del("stream_stats:details:{$app_stream_id}");
                                Redis::srem("stream_stats:active_ids", $app_stream_id);
                                return; // Exit StreamedResponse callback
                            }
                            echo $data;
                            flush();
                            usleep(10000); // Reduce CPU, similar to original
                        }
                        // Note: Symfony Process's getIterator with ITER_SKIP_ERR means stderr output is not directly processed here.
                        // If FFmpeg sends progress to stderr AND the -progress URL, only the URL part is handled by the new API endpoint.
                        // If FFmpeg sends progress ONLY to stderr (e.g. if -progress URL fails), that would be missed by the API endpoint
                        // and still logged by the main error logging of the Process component if not caught by getErrorOutput after wait().
                        $process->wait(); 

                        if ($process->isSuccessful()) {
                            Log::channel('ffmpeg')->info("Stream completed successfully for MergedChannel ID: {$mergedChannel->id}, Source URL: {$streamUrl}. AppStreamID: {$app_stream_id}");
                            $successfulStream = true;
                            Redis::hset("stream_stats:details:{$app_stream_id}", "status", "completed");
                            Redis::expire("stream_stats:details:{$app_stream_id}", 600); // Keep completed stats for 10 mins
                            Redis::srem("stream_stats:active_ids", $app_stream_id); // Remove from active
                            return; 
                        } else {
                            // Log the actual stderr output from FFmpeg process if it failed.
                            $errorOutput = $process->getErrorOutput();
                            Log::channel('ffmpeg')->error("FFmpeg process failed for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. Exit code: {$process->getExitCode()}. Output: {$errorOutput}. AppStreamID: {$app_stream_id}");
                            Redis::hset("stream_stats:details:{$app_stream_id}", "status", "failed_process_error");
                        }
                    } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                        Log::channel('ffmpeg')->warning("FFmpeg process timed out (idle) for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. Attempt {$attempt}. Error: {$e->getMessage()}. AppStreamID: {$app_stream_id}");
                        Redis::hset("stream_stats:details:{$app_stream_id}", "status", "failed_timeout");
                    } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $e) {
                        Log::channel('ffmpeg')->warning("FFmpeg process signaled for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. Attempt {$attempt}. Signal: {$e->getSignal()}. Error: {$e->getMessage()}. AppStreamID: {$app_stream_id}");
                        Redis::hset("stream_stats:details:{$app_stream_id}", "status", "failed_signaled");
                    } catch (\Exception $e) {
                        Log::channel('ffmpeg')->error("Exception during streaming for MergedChannel {$mergedChannel->id}, Source URL: {$streamUrl}. Attempt {$attempt}. Error: {$e->getMessage()}. AppStreamID: {$app_stream_id}");
                        if ($process && $process->isRunning()) $process->stop(0, SIGKILL); // Force kill
                        Redis::hset("stream_stats:details:{$app_stream_id}", "status", "failed_exception");
                    }
                    
                    if ($process && $process->isRunning()) $process->stop(0); // Ensure process is stopped before retrying or moving to next source
                    
                    // If this attempt failed, clean up its specific Redis entry before retrying or moving to next source
                    Redis::del("stream_stats:details:{$app_stream_id}");
                    Redis::srem("stream_stats:active_ids", $app_stream_id);

                } // End of retry loop for a single URL

                if ($successfulStream) break; // Exit the outer foreach loop (streamUrls)
            } // End of foreach loop for streamUrls

            if (!$successfulStream) {
                Log::channel('ffmpeg')->error("All source URLs failed for MergedChannel ID: {$mergedChannel->id}. AppStreamID Base: {$app_stream_id_base}");
                echo "Error: All stream sources failed for this merged channel."; // Provide a message to the client
            }
            // General cleanup for the base stream ID is handled by shutdown function
        }, 200, [
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform', // Match original controller
            'Content-Disposition' => "inline; filename=\"merged_stream.{$format}\"", // Meaningful filename
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

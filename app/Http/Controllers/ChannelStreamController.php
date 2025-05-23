<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'ts',
    ) {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlist = $channel->playlist;

        // Setup streams array
        $streamUrls = [
            $channel->url_custom ?? $channel->url
            // leave this here for future use...
            // @TODO: implement ability to assign fallback channels
        ];

        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
        ];

        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $settings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $settings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $settings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $settings['ffmpeg_path'],
            ];
        } catch (Exception $e) {
            // Ignore
        }

        // Get user agent
        $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);
        if ($playlist) {
            $userAgent = escapeshellarg($playlist->user_agent ?? $userAgent);
        }

        // Determine the output format
        $ip = $request->ip();
        $streamId = Str::random(8);
        $channelId = $channel->id;
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';
        $app_stream_id = "direct_{$channelId}_{$format}_{$streamId}"; // Unique ID for stream stats

        // Set the content type based on the format
        return new StreamedResponse(function () use ($channelId, $streamUrls, $title, $settings, $format, $ip, $streamId, $userAgent, $app_stream_id, $request) {
            // Set unique client key (order is used for stats output) - This is the old key, might be deprecated later
            $clientKey = "{$ip}::{$channelId}::{$streamId}";

            // Make sure PHP doesn't ignore user aborts
            ignore_user_abort(false);

            // Register a shutdown function that ALWAYS runs when the script dies
            register_shutdown_function(function () use ($clientKey, $title, $app_stream_id) {
                Redis::srem('mpts:active_ids', $clientKey); // Old active ID set
                Redis::del("stream_stats:details:{$app_stream_id}");
                Redis::srem("stream_stats:active_ids", $app_stream_id);
                Log::channel('ffmpeg')->info("Streaming stopped for channel {$title} (AppStreamID: {$app_stream_id})");
            });

            // Mark as active (old system)
            Redis::sadd('mpts:active_ids', $clientKey);

            // Clear any existing output buffers
            // This is important for real-time streaming
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Set the maximum number of retries
            $maxRetries = $settings['ffmpeg_max_tries'];

            // Get user defined options
            $userArgs = config('proxy.ffmpeg_additional_args', '');
            if (!empty($userArgs)) {
                $userArgs .= ' ';
            }

            // Get ffmpeg path
            $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegPath)) {
                $ffmpegPath = 'jellyfin-ffmpeg';
            }

            // Get ffmpeg output codec formats
            $videoCodec = config('proxy.ffmpeg_codec_video') ?: $settings['ffmpeg_codec_video'];
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];

            // Determine HW Accel Method
            $hw_accel_method_used = "None";
            if (str_contains($videoCodec, '_vaapi')) {
                $hw_accel_method_used = "VAAPI";
            } elseif (str_contains($videoCodec, '_qsv')) {
                $hw_accel_method_used = "QSV";
            }

            // Initial Stream Statistics Data
            $rawUserAgent = $request->header('User-Agent') ?? 'Unknown';
            $streamData = [
                'stream_id' => $app_stream_id,
                'channel_id' => $channelId,
                'channel_title' => $title,
                'client_ip' => $ip,
                'user_agent_raw' => $rawUserAgent,
                'stream_type' => "DIRECT_STREAM",
                'stream_format_requested' => $format,
                'video_codec_selected' => $videoCodec,
                'audio_codec_selected' => $audioCodec,
                'hw_accel_method_used' => $hw_accel_method_used,
                'ffmpeg_pid' => 'N/A', // PID not available at this stage or omitted
                'start_time_unix' => time(),
                'source_stream_url' => 'pending', // Will be updated in the loop
                'ffmpeg_command' => 'pending',    // Will be updated in the loop
            ];
            Redis::hmset("stream_stats:details:{$app_stream_id}", $streamData);
            Redis::sadd("stream_stats:active_ids", $app_stream_id);
            Redis::expire("stream_stats:details:{$app_stream_id}", 3600); // 1 hour expiry

            // Set the output format and codecs
            $output = $format === 'ts'
                ? "-c:v $videoCodec -c:a $audioCodec -c:s $subtitleCodec -f mpegts pipe:1"
                : "-c:v $videoCodec -c:a $audioCodec -bsf:a aac_adtstoasc -c:s $subtitleCodec -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            // Loop through available streams...
            foreach ($streamUrls as $streamUrl) {
                // Update source_stream_url for the current attempt
                Redis::hset("stream_stats:details:{$app_stream_id}", "source_stream_url", $streamUrl);

                // Initialize hardware acceleration arguments string
                $hwaccelArgsString = ''; // Initialize default
                if (str_contains($videoCodec, '_qsv')) {
                    $hwaccelArgsString = '-hwaccel qsv -qsv_device /dev/dri/renderD128 '; // QSV specific args
                } elseif (str_contains($videoCodec, '_vaapi')) {
                    $hwaccelArgsString = '-hwaccel vaapi -vaapi_device /dev/dri/renderD128 -hwaccel_output_format vaapi '; // VAAPI specific args
                }

                // Build the FFmpeg command
                $cmd = sprintf(
                    $ffmpegPath . ' ' . $hwaccelArgsString . // Hardware acceleration arguments prepended
                        // Pre-input HTTP options:
                        '-user_agent "%s" -referer "MyComputer" ' .
                        '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                        '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                        '-reconnect_delay_max 5 -noautorotate ' .

                        // User defined options:
                        '%s' .

                        // Input:
                        '-re -i "%s" ' .

                        // Progress tracking:
                        // '-progress pipe:2 ' . // Disabled for now

                        // Output:
                        '%s ' .

                        // Logging:
                        '%s',
                    $userAgent,                   // for -user_agent
                    $userArgs,                    // user defined options
                    $streamUrl,                   // input URL
                    $output,                      // for -f
                    $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
                );

                // Update ffmpeg_command in Redis
                Redis::hset("stream_stats:details:{$app_stream_id}", "ffmpeg_command", $cmd);

                // Log the command for debugging
                Log::channel('ffmpeg')->info("Streaming channel {$title} with command: {$cmd} (AppStreamID: {$app_stream_id})");

                // Continue trying until the client disconnects, or max retries are reached
                $retries = 0;
                while (!connection_aborted()) {
                    // Start the streaming process!
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);
                    try {
                        // Consider updating PID here if process starts successfully
                        // $process->start(); // if using start() instead of run()
                        // $pid = $process->getPid();
                        // if ($pid) {
                        //    Redis::hset("stream_stats:details:{$app_stream_id}", "ffmpeg_pid", $pid);
                        // }
                        $process->run(function ($type, $buffer) use ($channelId, $format, $app_stream_id) { // Pass $app_stream_id
                            if (connection_aborted()) {
                                // Explicit cleanup if connection aborts during streaming
                                Redis::del("stream_stats:details:{$app_stream_id}");
                                Redis::srem("stream_stats:active_ids", $app_stream_id);
                                throw new \Exception("Connection aborted by client.");
                            }
                            if ($type === SymphonyProcess::OUT) {
                                echo $buffer;
                                flush();
                                usleep(10000); // Reduce CPU usage
                            }
                            if ($type === SymphonyProcess::ERR) {
                                // split out each line
                                $lines = preg_split('/\r?\n/', trim($buffer));
                                foreach ($lines as $line) {
                                    Log::channel('ffmpeg')->error($line);
                                }
                            }
                        });
                    } catch (\Exception $e) {
                        // Log eror and attempt to reconnect.
                        if (!connection_aborted()) {
                            Log::channel('ffmpeg')
                                ->error("Error streaming channel (\"$title\"): " . $e->getMessage() . " (AppStreamID: {$app_stream_id})");
                        } else {
                            // If connection aborted led to this exception, ensure cleanup
                            Redis::del("stream_stats:details:{$app_stream_id}");
                            Redis::srem("stream_stats:active_ids", $app_stream_id);
                        }
                    }

                    // If we get here, the process ended.
                    if (connection_aborted()) {
                        if ($process->isRunning()) {
                            $process->stop(1); // SIGTERM then SIGKILL
                        }
                        // Cleanup already handled by shutdown function or exception block
                        return;
                    }
                    if (++$retries >= $maxRetries) {
                        // Log error and stop trying this stream...
                        Log::channel('ffmpeg')
                            ->error("FFmpeg error: max retries of $maxRetries reached for stream for channel $title. (AppStreamID: {$app_stream_id})");
                        
                        // No need to explicitly clean up here, shutdown function will handle it if stream truly ends.
                        // If we break, the outer "Error: No available streams" might be reached.
                        break; 
                    }
                    // Wait a short period before trying to reconnect.
                    sleep(min(8, $retries));
                }
            }
            // If loop finishes (e.g. max retries for all sources), the stream effectively failed.
            // The shutdown function will handle cleanup of the Redis stats.
            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'Content-Disposition' => "inline; filename=\"stream.$format\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

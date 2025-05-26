<?php

namespace App\Http\Controllers;

use App\Models\FailoverChannel;
use App\Models\Channel; // Used by the sources relationship
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess; // Alias for Symfony Process
use Exception; // For catching exceptions
use App\Exceptions\LowSpeedException; // Added for custom exception
use Illuminate\Support\Facades\Redirect; // Added for HLS playlist redirect

class FailoverStreamController extends Controller
{
    public function __invoke(Request $request, FailoverChannel $failoverChannel, string $format = 'ts')
    {
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        Log::channel('ffmpeg')->info("Attempting to stream Failover Channel: {$failoverChannel->name} (ID: {$failoverChannel->id})");
        // Sources are ordered by 'order' column in the pivot table via the model relationship
        $sources = $failoverChannel->sources()->get();

        if ($sources->isEmpty()) {
            Log::channel('ffmpeg')->error("Failover Channel {$failoverChannel->name} (ID: {$failoverChannel->id}) has no sources defined.");
            return response("Failover channel has no sources.", 500);
        }

        Log::channel('ffmpeg')->info("Sources for {$failoverChannel->name}: " . $sources->map(function ($s) { return $s->name ?? $s->title ?? $s->id; })->toJson() . ". Speed threshold: {$failoverChannel->speed_threshold}x");

        $settings = $this->getStreamSettings();
        // $userAgent is already escaped by escapeshellarg() in getStreamSettings if it's fetched from there,
        // or here if we fetch it directly and escape.
        // For consistency, let's ensure it's fetched and escaped before the loop.
        $baseUserAgent = $settings['ffmpeg_user_agent']; // User agent from settings

        foreach ($sources as $sourceChannel) {
            // Per-source user agent (if playlist specific) or fallback to general.
            // $userAgent needs to be specific to this $sourceChannel if its playlist has one.
            // This logic might need to be more sophisticated if playlist is not loaded on $sourceChannel by default.
            // Assuming $sourceChannel->playlist is available if needed:
            $currentStreamUserAgent = $sourceChannel->playlist->user_agent ?? $baseUserAgent;
            $escapedUserAgent = escapeshellarg($currentStreamUserAgent);

            Log::channel('ffmpeg')->info("Attempting source: {$sourceChannel->name} (ID: {$sourceChannel->id}) for Failover Channel {$failoverChannel->name}");

            $streamUrl = $sourceChannel->url_custom ?? $sourceChannel->url;
            $currentStreamTitle = strip_tags($sourceChannel->title_custom ?? $sourceChannel->title ?? $sourceChannel->name ?? "Source {$sourceChannel->id}");
            
            $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegPath)) $ffmpegPath = 'jellyfin-ffmpeg';

            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = ''; // Placeholder for future codec-specific args other than VA-API filter

            $videoCodec = $sourceChannel->video_codec_custom ?? $settings['ffmpeg_codec_video']; // Use channel specific, then general, then default
            $audioCodec = $sourceChannel->audio_codec_custom ?? $settings['ffmpeg_codec_audio'];
            $subtitleCodec = $sourceChannel->subtitle_codec_custom ?? $settings['ffmpeg_codec_subtitles'];


            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = 'h264_vaapi';
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$settings['ffmpeg_vaapi_device']} ";
                $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf '" . trim($settings['ffmpeg_vaapi_video_filter'], "'") . "' ";
                }
            }

            $outputFormatString = $format === 'ts'
                ? "-c:v $videoCodec -c:a $audioCodec -c:s $subtitleCodec -f mpegts pipe:1"
                : "-c:v $videoCodec -ac 2 -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
            
            $userArgs = $settings['ffmpeg_additional_args'] ?? '';
            if (!empty($userArgs) && substr($userArgs, -1) !== ' ') $userArgs .= ' ';

            $cmd = $ffmpegPath . ' ';
            $cmd .= $hwaccelInitArgs;
            $cmd .= $hwaccelArgs;
            $cmd .= "-user_agent ".$escapedUserAgent." -referer \"MyComputer\" " .
                    '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                    '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                    '-reconnect_delay_max 5';
            if (stripos($streamUrl, '.mkv') !== false) {
                $cmd .= ' -analyzeduration 10M -probesize 10M';
            }
            $cmd .= ' -noautorotate ';
            $cmd .= $userArgs;
            $cmd .= $codecSpecificArgs;
            $cmd .= '-re -i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs;
            $cmd .= $outputFormatString;
            $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -loglevel error');

            Log::channel('ffmpeg')->info("Executing FFmpeg for source [{$currentStreamTitle}]: {$cmd}");
            
            // Shared variable for low speed detection, reset for each source
            // Needs to be an object or array to be modifiable by reference in the callback.
            $status = ['lowSpeedCount' => 0, 'processFailed' => false, 'clientAborted' => false];

            try {
                return new StreamedResponse(function () use ($cmd, $currentStreamTitle, $failoverChannel, &$status) {
                    ignore_user_abort(false);
                    while (ob_get_level()) ob_end_flush();
                    flush();
                    ini_set('zlib.output_compression', 0);

                    $process = SymfonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);

                    $process->run(function ($type, $buffer) use ($currentStreamTitle, $failoverChannel, &$status) {
                        if (connection_aborted()) {
                            Log::channel('ffmpeg')->info("Connection aborted by client for [{$currentStreamTitle}].");
                            $status['clientAborted'] = true;
                            throw new Exception("CLIENT_ABORTED_FAILOVER"); 
                        }

                        if ($type === SymfonyProcess::OUT) {
                            echo $buffer;
                            flush();
                            usleep(10000); 
                        } elseif ($type === SymfonyProcess::ERR) {
                            $lines = preg_split('/\r?\n/', trim($buffer));
                            foreach ($lines as $line) {
                                if (preg_match('/speed=\s*([0-9\.]+x)/', $line, $matches)) {
                                    $speedValue = rtrim($matches[1], 'x');
                                    Log::channel('ffmpeg')->info("FFmpeg speed for [{$currentStreamTitle}]: {$speedValue}x");
                                    if ((float)$speedValue < (float)$failoverChannel->speed_threshold && (float)$speedValue > 0.0) {
                                        $status['lowSpeedCount']++;
                                        Log::channel('ffmpeg')->warning("Low speed count for [{$currentStreamTitle}]: {$status['lowSpeedCount']}");
                                        if ($status['lowSpeedCount'] >= 3) { // Consecutive low speed readings
                                            Log::channel('ffmpeg')->error("Speed for [{$currentStreamTitle}] consistently below threshold ({$failoverChannel->speed_threshold}x). Triggering failover by exception.");
                                            throw new LowSpeedException("FFMPEG_SPEED_LOW_FAILOVER");
                                        }
                                    } else {
                                        $status['lowSpeedCount'] = 0; 
                                    }
                                } elseif (!empty(trim($line))) {
                                    Log::channel('ffmpeg')->error("FFmpeg (source: [{$currentStreamTitle}]): {$line}");
                                }
                            }
                        }
                    });

                    if (!$process->isSuccessful() && !$status['clientAborted'] && !($process->getExitCode() === null && $process->isRunning())) {
                        // Process exited with an error, not due to client abort or speed low (which throws)
                        Log::channel('ffmpeg')->error("FFmpeg process for source [{$currentStreamTitle}] was not successful. Exit code: " . $process->getExitCode() . ". Output: " . $process->getErrorOutput());
                        $status['processFailed'] = true; 
                    }
                }, 200, [
                    'Content-Type' => $format === 'ts' ? 'video/MP2T' : 'video/mp4',
                    'Connection' => 'keep-alive',
                    'Cache-Control' => 'no-store, no-transform',
                    'Content-Disposition' => 'inline; filename="stream.' . $format . '"',
                    'X-Accel-Buffering' => 'no',
                ]);

            } catch (LowSpeedException $e) {
                Log::channel('ffmpeg')->info("Low speed detected for source [{$currentStreamTitle}]. Trying next source for Failover Channel [{$failoverChannel->name}].");
                continue; // Try next source
            } catch (Exception $e) {
                if ($e->getMessage() === "CLIENT_ABORTED_FAILOVER") {
                    Log::channel('ffmpeg')->info("Client aborted during stream of [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]. Stopping failover attempts.");
                    return response("Stream aborted by client.", 499); // Special code for client abort
                }
                Log::channel('ffmpeg')->error("Error streaming source [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]: " . $e->getMessage());
                if($status['processFailed']){ // If it was a process failure not caught by specific exceptions.
                     // This check might be redundant if the process failing always throws an exception that gets here.
                }
                continue; // Try next source
            }
        }

        Log::channel('ffmpeg')->error("All sources for Failover Channel {$failoverChannel->name} failed.");
        return response("All sources failed or no suitable stream found.", 503); // Service Unavailable
    }
    
    /**
     * Get all settings needed for streaming (copied from StreamController)
     */
    protected function getStreamSettings(): array
    {
        $userPreferences = app(GeneralSettings::class);
        // Initialize with defaults that match GeneralSettings defaults for these new fields
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy', // Default, will be overridden by VA-API if enabled
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
            'ffmpeg_vaapi_enabled' => false,
            'ffmpeg_vaapi_device' => '/dev/dri/renderD128',
            'ffmpeg_vaapi_video_filter' => 'scale_vaapi=format=nv12',
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
                
                'ffmpeg_vaapi_enabled' => $userPreferences->ffmpeg_vaapi_enabled ?? $settings['ffmpeg_vaapi_enabled'],
                'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $settings['ffmpeg_vaapi_device'],
                'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $settings['ffmpeg_vaapi_video_filter'],
            ];

            $settings['ffmpeg_additional_args'] = config('proxy.ffmpeg_additional_args', '');
        } catch (Exception $e) {
            Log::error("Error fetching stream settings: " . $e->getMessage());
            // Keep default settings if UserPreferences fail
        }
        return $settings;
    }

    public function serveHlsPlaylist(Request $request, FailoverChannel $failoverChannel)
    {
        Log::channel('ffmpeg')->info("HLS playlist requested for Failover Channel: {$failoverChannel->name} (ID: {$failoverChannel->id})");

        // Get the first enabled source channel, ordered by the 'order' column in the pivot table
        $primarySource = $failoverChannel->sources()
                                       ->where('channels.enabled', true) // Ensure the channel itself is enabled
                                       ->first();

        if (!$primarySource) {
            Log::channel('ffmpeg')->error("No enabled sources found for Failover Channel HLS: {$failoverChannel->name}");
            abort(404, 'No enabled sources found for this failover channel.');
        }

        Log::channel('ffmpeg')->info("Using source channel '{$primarySource->name}' (ID: {$primarySource->id}) for HLS playlist for Failover Channel '{$failoverChannel->name}'.");

        // Construct the URL to the HLS playlist of the chosen source channel.
        // This assumes that HLS streams for individual channels are served at a path like /hls/{channel_id}/stream.m3u8
        // This path is typically handled by Nginx X-Accel-Redirect or similar, pointing to where HlsStreamService stores the files.
        // The HlsStreamService itself uses Storage::disk('app')->path("hls/{$id}") for channels.
        // The public URL might be different based on how HLS segments are served (e.g., directly by PHP or via Nginx).
        // For now, we'll construct a common pattern. If this specific URL doesn't work,
        // it indicates the need to look at how HLS URLs are made public for individual channels.
        
        // The HLS segments are typically served via HlsStreamController.php which handles /hls/{type}/{id}/{segment}
        // The playlist itself is also served by HlsStreamController.php at /hls/{type}/{id}/stream.m3u8
        // So, we need to ensure 'type' is correctly identified. For a Channel source, type is 'channel'.
        // Assuming the route 'hls.playlist' exists and is correctly defined.
        $targetHlsPlaylistUrl = route('hls.playlist', [
            'type' => 'channel', // Since the source is a Channel model
            'id' => $primarySource->id
        ]);

        Log::channel('ffmpeg')->info("Redirecting Failover HLS request for '{$failoverChannel->name}' to source '{$primarySource->name}' HLS playlist: {$targetHlsPlaylistUrl}");
        return Redirect::to($targetHlsPlaylistUrl);
    }
}

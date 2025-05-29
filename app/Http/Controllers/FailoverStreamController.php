<?php

namespace App\Http\Controllers;

use App\Models\FailoverChannel;
use App\Models\Channel; // Used by the sources relationship
use App\Models\Playlist; // Added for potential type hinting / clarity
use App\Models\PlaylistProfile; // Added for clarity
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis; // Added for Redis
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymfonyProcess; // Correctly aliased
use Exception; // For catching exceptions
use App\Exceptions\LowSpeedException; // Added for custom exception
use Illuminate\Support\Facades\Redirect; // Added for HLS playlist redirect
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Services\HlsStreamService;

class FailoverStreamController extends Controller
{
    protected HlsStreamService $hlsService;

    // Cache configuration for bad sources
    private const BAD_SOURCE_CACHE_MINUTES = 5;
    private const BAD_SOURCE_CACHE_PREFIX = 'failover:bad_source:';

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

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
        $baseUserAgent = $settings['ffmpeg_user_agent']; 

        foreach ($sources as $sourceChannel) {
            // --- Bad Source Cache Check START ---
            $badSourceCacheKey = self::BAD_SOURCE_CACHE_PREFIX . $sourceChannel->id;
            if (Redis::exists($badSourceCacheKey)) {
                Log::channel('ffmpeg')->info("Skipping source ID {$sourceChannel->id} for Failover Channel {$failoverChannel->name} as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                continue;
            }
            // --- Bad Source Cache Check END ---

            $currentStreamUserAgent = $sourceChannel->playlist->user_agent ?? $baseUserAgent;
            $escapedUserAgent = escapeshellarg($currentStreamUserAgent);

            Log::channel('ffmpeg')->info("Attempting source: {$sourceChannel->name} (ID: {$sourceChannel->id}) for Failover Channel {$failoverChannel->name}");

            $streamUrl = $sourceChannel->url_custom ?? $sourceChannel->url;
            $currentStreamTitle = strip_tags($sourceChannel->title_custom ?? $sourceChannel->title ?? $sourceChannel->name ?? "Source {$sourceChannel->id}");

            // --- Playlist Profile Stream Limit Check START ---
            $playlist = $sourceChannel->playlist;

            if (!$playlist) {
                Log::channel('ffmpeg')->error("Source channel {$sourceChannel->id} ({$currentStreamTitle}) has no associated playlist. Skipping.");
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to no playlist for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                continue;
            }

            // The defaultProfile() method already returns the first active default profile or null
            $playlistProfile = $playlist->defaultProfile(); 

            if (!$playlistProfile) { // Handles both null and potentially inactive if logic changes in defaultProfile()
                Log::channel('ffmpeg')->info("Playlist {$playlist->name} (ID: {$playlist->id}) for source {$sourceChannel->id} has no active default profile. Skipping source.");
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to no active default profile for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                continue;
            }
            
            // Check if is_active is explicitly false (though defaultProfile should handle this)
            if (!$playlistProfile->is_active) {
                Log::channel('ffmpeg')->info("Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) for playlist {$playlist->id} is not active. Skipping source {$sourceChannel->id}.");
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to inactive profile for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                continue;
            }

            if (isset($playlistProfile->max_streams)) {
                $redisKey = "profile_connections:" . $playlistProfile->id;
                $current_connections = (int) Redis::get($redisKey);

                if ($current_connections >= $playlistProfile->max_streams) {
                    Log::channel('ffmpeg')->warning("Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) has reached its maximum stream limit of {$playlistProfile->max_streams} connections. Current: {$current_connections}. Skipping source {$sourceChannel->id}.");
                    Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                    Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to profile stream limit for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                    continue;
                }
                Log::channel('ffmpeg')->info("Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) connection check: {$current_connections} / {$playlistProfile->max_streams}. Proceeding with source {$sourceChannel->id}.");
            } else {
                Log::channel('ffmpeg')->info("Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) has no maximum stream limit defined. Proceeding with source {$sourceChannel->id}.");
            }
            // --- Playlist Profile Stream Limit Check END ---
            
            // --- FFprobe Pre-check START ---
            Log::channel('ffmpeg')->info("[PRE-CHECK] Attempting ffprobe for source: {$currentStreamTitle} (URL: {$streamUrl})");

            // Determine the command/path for ffmpeg execution first
            $ffmpegCommandToExecute = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegCommandToExecute)) {
                $ffmpegCommandToExecute = 'jellyfin-ffmpeg'; // Default ffmpeg command
            }

            // Now, derive the ffprobe path based on ffmpegCommandToExecute
            if (str_contains($ffmpegCommandToExecute, '/')) {
                // If $ffmpegCommandToExecute is a full path (e.g., /usr/bin/ffmpeg),
                // assume ffprobe is in the same directory.
                $ffprobePath = dirname($ffmpegCommandToExecute) . '/ffprobe';
            } else {
                // If $ffmpegCommandToExecute is just a command name (e.g., 'jellyfin-ffmpeg' or 'ffmpeg'),
                // assume 'ffprobe' is the command for ffprobe and is in the system PATH.
                $ffprobePath = 'ffprobe';
            }

            $precheckCmd = $ffprobePath . " -v quiet -print_format json -show_streams -select_streams v:0 -user_agent " . escapeshellarg($currentStreamUserAgent) . " -multiple_requests 1 -reconnect_on_network_error 1 -reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 -reconnect_delay_max 2 -timeout 5000000 " . escapeshellarg($streamUrl);
            Log::channel('ffmpeg')->info("[PRE-CHECK] Executing ffprobe command for [{$currentStreamTitle}]: {$precheckCmd}");
            
            $precheckProcess = SymfonyProcess::fromShellCommandline($precheckCmd);
            $precheckProcess->setTimeout(7); // 7-second timeout for ffprobe

            try {
                $precheckProcess->run();
                if (!$precheckProcess->isSuccessful()) {
                    Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$currentStreamTitle}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                    Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                    Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to ffprobe failure for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                    // Add to bad source cache
                    $cacheReason = "failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")";
                    Redis::setex($badSourceCacheKey, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReason);
                    Log::channel('ffmpeg')->info("Added source ID {$sourceChannel->id} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to ffprobe failure. Reason: {$cacheReason}");
                    continue; // Try next source
                }
                Log::channel('ffmpeg')->info("[PRE-CHECK] ffprobe successful for source [{$currentStreamTitle}].");

                // --- Detailed ffprobe logging START ---
                $ffprobeOutput = $precheckProcess->getOutput();
                $streamInfo = json_decode($ffprobeOutput, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($streamInfo['streams'])) {
                    foreach ($streamInfo['streams'] as $stream) {
                        if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                            $codecName = $stream['codec_name'] ?? 'N/A';
                            $pixFmt = $stream['pix_fmt'] ?? 'N/A';
                            $width = $stream['width'] ?? 'N/A';
                            $height = $stream['height'] ?? 'N/A';
                            $profile = $stream['profile'] ?? 'N/A';
                            $level = $stream['level'] ?? 'N/A';
                            Log::channel('ffmpeg')->info("[PRE-CHECK] Source [{$currentStreamTitle}] video stream: Codec: {$codecName}, Format: {$pixFmt}, Resolution: {$width}x{$height}, Profile: {$profile}, Level: {$level}");
                            // Typically, we're interested in the first video stream.
                            // If multiple video streams are possible and need specific handling, this loop can be adjusted.
                            break; 
                        }
                    }
                } else {
                    Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output or no streams found for [{$currentStreamTitle}]. Output: " . $ffprobeOutput);
                }
                // --- Detailed ffprobe logging END ---

            } catch (Exception $e) { // Catches ProcessTimedOutException, ProcessFailedException etc. from run()
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe exception for source [{$currentStreamTitle}]: " . $e->getMessage());
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to ffprobe exception for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                // Add to bad source cache
                $cacheReason = "failed_ffprobe_exception (" . $e->getMessage() . ")";
                Redis::setex($badSourceCacheKey, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReason);
                Log::channel('ffmpeg')->info("Added source ID {$sourceChannel->id} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to ffprobe exception. Reason: {$cacheReason}");
                continue; // Try next source
            }
            // --- FFprobe Pre-check END ---

            // Reset status for the main FFmpeg attempt, only if ffprobe passed
            $status = ['lowSpeedCount' => 0, 'processFailed' => false, 'clientAborted' => false];

            // --- Successful Stream Start Redis State Updates START ---
            Redis::set("channel_stream:" . $failoverChannel->id, $sourceChannel->id);
            Redis::set("stream_profile:" . $sourceChannel->id, $playlistProfile->id); // Assuming $playlistProfile is the one confirmed for this stream
            $metadata = [
                'url' => $streamUrl, // Use the actual stream URL being used
                'worker_id' => 'main', // Or a configurable ID
                'stream_id' => $sourceChannel->id,
                'm3u_profile_id' => $playlistProfile->id,
                'state' => 'ACTIVE',
                'last_switch_time' => time(),
                'state_change_time' => time()
            ];
            Redis::hmset("channel_metadata:" . $failoverChannel->id, $metadata);
            Log::channel('ffmpeg')->info("FailoverStream: Set Redis states for successful stream start. FailoverChannel ID: {$failoverChannel->id}, SourceChannel ID: {$sourceChannel->id}, Profile ID: {$playlistProfile->id}, Metadata: " . json_encode($metadata));
            // --- Successful Stream Start Redis State Updates END ---

            // --- Increment Playlist Profile Connection Counter START ---
            $activeProfileIdForDecrement = null;
            if ($playlistProfile && isset($playlistProfile->max_streams)) {
                $redisKey = "profile_connections:" . $playlistProfile->id;
                Redis::incr($redisKey);
                Log::channel('ffmpeg')->info("Incremented profile_connections for profile ID: {$playlistProfile->id}. Current: " . Redis::get($redisKey));
                $activeProfileIdForDecrement = $playlistProfile->id;
            }
            // --- Increment Playlist Profile Connection Counter END ---
            
            $ffmpegPath = $ffmpegCommandToExecute; // Use the resolved command for the main ffmpeg execution

            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = '';

            $audioCodec = $sourceChannel->audio_codec_custom ?? $settings['ffmpeg_codec_audio'];
            $subtitleCodec = $sourceChannel->subtitle_codec_custom ?? $settings['ffmpeg_codec_subtitles'];

            // Initialize videoCodec, it will be set based on acceleration method
            $videoCodec = ''; 

            if (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi') {
                $videoCodec = 'h264_vaapi'; // VAAPI specific codec
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$settings['ffmpeg_vaapi_device']} ";
                $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    // Ensure quotes are handled correctly if the filter string already contains them
                    $trimmedFilter = trim($settings['ffmpeg_vaapi_video_filter'], "'\"");
                    $videoFilterArgs = "-vf '" . $trimmedFilter . "' ";
                }
            } else {
                // Logic for other acceleration methods or software encoding
                $videoCodec = $sourceChannel->video_codec_custom ?? $settings['ffmpeg_codec_video'];
                // Potentially add other hardware acceleration logic here (qsv, nvenc) if needed in the future
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
                    '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
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
            
            // $status is now reset before this try block thanks to the pre-check logic above

            try {
                return new StreamedResponse(function () use ($cmd, $currentStreamTitle, $failoverChannel, &$status, $activeProfileIdForDecrement) {
                    // Register shutdown function to decrement counter on script exit/abort
                    register_shutdown_function(function () use ($activeProfileIdForDecrement) {
                        if ($activeProfileIdForDecrement) {
                            $redisKey = "profile_connections:" . $activeProfileIdForDecrement;
                            Redis::decr($redisKey);
                            Log::channel('ffmpeg')->info("Decremented profile_connections for profile ID: {$activeProfileIdForDecrement}. Current: " . Redis::get($redisKey));
                        }
                    });

                    ignore_user_abort(false);
                    while (ob_get_level()) ob_end_flush();
                    flush();
                    ini_set('zlib.output_compression', 0);

                    // Use the aliased SymfonyProcess
                    $process = SymfonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null);

                    $process->run(function ($type, $buffer) use ($currentStreamTitle, $failoverChannel, &$status) {
                        if (connection_aborted()) {
                            Log::channel('ffmpeg')->info("Connection aborted by client for [{$currentStreamTitle}].");
                            $status['clientAborted'] = true;
                            throw new Exception("CLIENT_ABORTED_FAILOVER"); 
                        }

                        if ($type === SymfonyProcess::OUT) { // Use aliased SymfonyProcess here too
                            echo $buffer;
                            flush();
                            usleep(10000); 
                        } elseif ($type === SymfonyProcess::ERR) { // And here
                            $lines = preg_split('/\r?\n/', trim($buffer)); // Corrected regex for preg_split
                            foreach ($lines as $line) {
                                if (preg_match('/speed=\s*([0-9\.]+x)/', $line, $matches)) {
                                    $speedValue = rtrim($matches[1], 'x');
                                    Log::channel('ffmpeg')->info("FFmpeg speed for [{$currentStreamTitle}]: {$speedValue}x");
                                    if ((float)$speedValue < (float)$failoverChannel->speed_threshold && (float)$speedValue > 0.0) {
                                        $status['lowSpeedCount']++;
                                        Log::channel('ffmpeg')->warning("Low speed count for [{$currentStreamTitle}]: {$status['lowSpeedCount']}");
                                        if ($status['lowSpeedCount'] >= 3) {
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
                        Log::channel('ffmpeg')->error("FFmpeg process for source [{$currentStreamTitle}] was not successful. Exit code: " . $process->getExitCode() . ". Output: " . $process->getErrorOutput());
                        Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                        Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to FFmpeg process failure for FailoverChannel ID: {$failoverChannel->id}");
                        $status['processFailed'] = true;
                        // Add to bad source cache if process failed and not client aborted
                        if (!$status['clientAborted']) {
                            $cacheReasonFfmpeg = "failed_ffmpeg (Exit: " . $process->getExitCode() . ")";
                            $badSourceCacheKeyFfmpeg = self::BAD_SOURCE_CACHE_PREFIX . $sourceChannel->id; // Define key for this scope
                            Redis::setex($badSourceCacheKeyFfmpeg, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReasonFfmpeg);
                            Log::channel('ffmpeg')->info("Added source ID {$sourceChannel->id} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to ffmpeg process failure. Reason: {$cacheReasonFfmpeg}");
                        }
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
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to LowSpeedException for FailoverChannel ID: {$failoverChannel->id}");
                // Add to bad source cache for low speed
                $cacheReasonLowSpeed = "failed_ffmpeg_low_speed";
                $badSourceCacheKeyLowSpeed = self::BAD_SOURCE_CACHE_PREFIX . $sourceChannel->id; // Define key
                Redis::setex($badSourceCacheKeyLowSpeed, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReasonLowSpeed);
                Log::channel('ffmpeg')->info("Added source ID {$sourceChannel->id} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to low speed. Reason: {$cacheReasonLowSpeed}");
                // No need to manually decrement here, shutdown function will handle it if $activeProfileIdForDecrement was set.
                continue; 
            } catch (Exception $e) {
                if ($e->getMessage() === "CLIENT_ABORTED_FAILOVER") {
                    Log::channel('ffmpeg')->info("Client aborted during stream of [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]. Stopping failover attempts.");
                    Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'CLIENT_ABORT', 'state_change_time' => time()]);
                    Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to CLIENT_ABORT for FailoverChannel ID: {$failoverChannel->id}");
                    // Shutdown function will handle decrement if $activeProfileIdForDecrement was set.
                    return response("Stream aborted by client.", 499); 
                }
                Log::channel('ffmpeg')->error("Error streaming source [{$currentStreamTitle}] for Failover Channel [{$failoverChannel->name}]: " . $e->getMessage());
                Redis::hmset("channel_metadata:" . $failoverChannel->id, ['state' => 'SWITCHING', 'state_change_time' => time()]);
                Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to SWITCHING due to general Exception for FailoverChannel ID: {$failoverChannel->id}, Source ID: {$sourceChannel->id}");
                // Add to bad source cache for general ffmpeg exceptions
                $cacheReasonException = "failed_ffmpeg_exception (" . $e->getMessage() . ")";
                $badSourceCacheKeyException = self::BAD_SOURCE_CACHE_PREFIX . $sourceChannel->id; // Define key
                Redis::setex($badSourceCacheKeyException, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReasonException);
                Log::channel('ffmpeg')->info("Added source ID {$sourceChannel->id} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to ffmpeg exception. Reason: {$cacheReasonException}");
                // No need to manually decrement here, shutdown function will handle it if $activeProfileIdForDecrement was set.
                continue; 
            }
        }

        Log::channel('ffmpeg')->error("All sources for Failover Channel {$failoverChannel->name} failed (or were skipped due to recent failures).");
        Redis::hmset("channel_metadata:" . $failoverChannel->id, [
            'url' => '',
            'stream_id' => '',
            'm3u_profile_id' => '',
            'state' => 'ERROR',
            'state_change_time' => time()
        ]);
        Log::channel('ffmpeg')->info("FailoverStream: Set channel_metadata state to ERROR as all sources failed for FailoverChannel ID: {$failoverChannel->id}");
        return response("All sources failed or no suitable stream found.", 503); 
    }
    
    protected function getStreamSettings(): array
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
            // 'ffmpeg_vaapi_enabled' => false, // Removed
            'hardware_acceleration_method' => 'none', // Added
            'ffmpeg_vaapi_device' => '/dev/dri/renderD128',
            'ffmpeg_vaapi_video_filter' => 'scale_vaapi=format=nv12', // Default, might be overwritten or conditionally changed
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
                'hardware_acceleration_method' => $userPreferences->hardware_acceleration_method ?? $settings['hardware_acceleration_method'], // Fetched
                'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $settings['ffmpeg_vaapi_device'],
                'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $settings['ffmpeg_vaapi_video_filter'], // Fetched
            ];
            $settings['ffmpeg_additional_args'] = config('proxy.ffmpeg_additional_args', '');

            // Apply conditional default for ffmpeg_vaapi_video_filter
            if (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi' && empty($settings['ffmpeg_vaapi_video_filter'])) {
                $settings['ffmpeg_vaapi_video_filter'] = 'scale_vaapi=format=nv12'; // Default VAAPI filter
            }

        } catch (Exception $e) {
            Log::error("Error fetching stream settings: " . $e->getMessage());
        }
        return $settings;
    }

    public function serveHlsPlaylist(Request $request, FailoverChannel $failoverChannel)
    {
        Log::channel('ffmpeg')->info("HLS playlist requested for Failover Channel: {$failoverChannel->name} (ID: {$failoverChannel->id})");

        $sessionCacheKey = 'hls:failover_session:' . $failoverChannel->id;
        $sessionData = Cache::get($sessionCacheKey);

        $sources = $sessionData['sources_list'] ?? null;
        $currentSourceIndex = $sessionData['current_source_index'] ?? -1;
        $currentSource = null;
        $pid = $sessionData['current_ffmpeg_pid'] ?? null;
        $currentSourceChannelId = $sessionData['current_source_channel_id'] ?? null;

        if ($sources === null) {
            Log::channel('ffmpeg')->info("HLS Failover: No session found for {$failoverChannel->name}. Initializing sources.");
            $allEnabledSources = $failoverChannel->sources()
                                               ->where('channels.enabled', true)
                                               ->orderBy('pivot_order', 'asc') // Ensure 'pivot_order' is correct
                                               ->get();

            if ($allEnabledSources->isEmpty()) {
                Log::channel('ffmpeg')->error("HLS Failover: No enabled sources for {$failoverChannel->name}.");
                abort(404, 'No enabled sources found for this failover channel.');
            }

            $sources = $allEnabledSources->map(function ($src) {
                return [
                    'id' => $src->id,
                    'url' => $src->url_custom ?? $src->url,
                    'user_agent' => $src->playlist->user_agent ?? null,
                    'title' => strip_tags($src->title_custom ?? $src->title ?? $src->name ?? "Source {$src->id}")
                ];
            })->toArray();
            
            $currentSourceIndex = -1; // Will be incremented before first use
            $pid = null;
            Log::channel('ffmpeg')->info("HLS Failover: Sources initialized for {$failoverChannel->name}. Found " . count($sources) . " sources.");
        }

        if ($pid !== null && $currentSourceChannelId !== null && $this->hlsService->isRunning('channel', $currentSourceChannelId)) {
            // Current source is presumably healthy
            $currentSource = $sources[$currentSourceIndex];
            Log::channel('ffmpeg')->info("HLS Failover: Existing FFmpeg process (PID: {$pid}) for source {$currentSource['id']} is running for {$failoverChannel->name}.");
        } else {
            if ($pid !== null) {
                 Log::channel('ffmpeg')->warning("HLS Failover: FFmpeg process (PID: {$pid}) for source {$currentSourceChannelId} is no longer running for {$failoverChannel->name}. Attempting next source.");
            }
            $currentSourceIndex++;

            // Loop to find the next valid, non-cached source
            while ($currentSourceIndex < count($sources)) {
                $currentSource = $sources[$currentSourceIndex];
                $currentSourceChannelId = $currentSource['id'];

                // --- Bad Source Cache Check for HLS START ---
                $badSourceCacheKey = self::BAD_SOURCE_CACHE_PREFIX . $currentSourceChannelId;
                if (Redis::exists($badSourceCacheKey)) {
                    Log::channel('ffmpeg')->info("HLS Failover: Skipping source ID {$currentSourceChannelId} ({$currentSource['title']}) for Failover Channel {$failoverChannel->name} as it was recently marked as bad. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                    $currentSourceIndex++; // Move to next index
                    $pid = null; // Ensure PID is null as we are skipping this source
                    continue; // Re-evaluate the while condition with the new index
                }
                // --- Bad Source Cache Check for HLS END ---
                break; // Found a non-cached source or exhausted list
            }

            if ($currentSourceIndex >= count($sources) || $currentSource === null) {
                Cache::forget($sessionCacheKey);
                Log::channel('ffmpeg')->error("HLS Failover: All sources exhausted or skipped due to cache for {$failoverChannel->name}.");
                abort(503, "All HLS sources for Failover Channel {$failoverChannel->name} failed, are unavailable, or recently failed.");
            }
            
            // $currentSource and $currentSourceChannelId are now set to a non-cached source

            // --- Playlist Profile Stream Limit Check for HLS START ---
            Log::channel('ffmpeg')->info("HLS Failover: Evaluating source {$currentSource['id']} ({$currentSource['title']}) at index {$currentSourceIndex} for profile limits.");
            $sourceChannelModel = Channel::find($currentSource['id']);

            if (!$sourceChannelModel) {
                Log::channel('ffmpeg')->error("HLS Failover: Source channel model ID {$currentSource['id']} not found. Skipping.");
                $currentSourceIndex++;
                $sessionData = [
                    'sources_list' => $sources,
                    'current_source_index' => $currentSourceIndex,
                    'current_source_channel_id' => ($currentSourceIndex < count($sources)) ? $sources[$currentSourceIndex]['id'] : null,
                    'current_ffmpeg_pid' => null
                ];
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                return $this->serveHlsPlaylist($request, $failoverChannel);
            }

            $playlist = $sourceChannelModel->playlist;
            if (!$playlist) {
                Log::channel('ffmpeg')->error("HLS Failover: Playlist not found for source channel {$sourceChannelModel->id}. Skipping.");
                $currentSourceIndex++;
                $sessionData = [
                    'sources_list' => $sources,
                    'current_source_index' => $currentSourceIndex,
                    'current_source_channel_id' => ($currentSourceIndex < count($sources)) ? $sources[$currentSourceIndex]['id'] : null,
                    'current_ffmpeg_pid' => null
                ];
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                return $this->serveHlsPlaylist($request, $failoverChannel);
            }

            $playlistProfile = $playlist->defaultProfile();
            if (!$playlistProfile || !$playlistProfile->is_active) {
                Log::channel('ffmpeg')->info("HLS Failover: No active default profile for playlist {$playlist->name} (ID: {$playlist->id}). Skipping source {$sourceChannelModel->id}.");
                $currentSourceIndex++;
                $sessionData = [
                    'sources_list' => $sources,
                    'current_source_index' => $currentSourceIndex,
                    'current_source_channel_id' => ($currentSourceIndex < count($sources)) ? $sources[$currentSourceIndex]['id'] : null,
                    'current_ffmpeg_pid' => null
                ];
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                return $this->serveHlsPlaylist($request, $failoverChannel);
            }

            if (isset($playlistProfile->max_streams)) {
                $redisKey = "profile_connections:" . $playlistProfile->id;
                $current_connections = (int) Redis::get($redisKey);

                if ($current_connections >= $playlistProfile->max_streams) {
                    Log::channel('ffmpeg')->warning("HLS Failover: Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) at stream limit ({$playlistProfile->max_streams}). Current: {$current_connections}. Skipping source {$sourceChannelModel->id}.");
                    $currentSourceIndex++;
                    $sessionData = [
                        'sources_list' => $sources,
                        'current_source_index' => $currentSourceIndex,
                        'current_source_channel_id' => ($currentSourceIndex < count($sources)) ? $sources[$currentSourceIndex]['id'] : null,
                        'current_ffmpeg_pid' => null
                    ];
                    Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                    // This recursive call will re-evaluate based on the new currentSourceIndex
                    return $this->serveHlsPlaylist($request, $failoverChannel);
                }
                Log::channel('ffmpeg')->info("HLS Failover: Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) connection check: {$current_connections} / {$playlistProfile->max_streams}. Proceeding with source {$sourceChannelModel->id}.");
            } else {
                Log::channel('ffmpeg')->info("HLS Failover: Playlist profile {$playlistProfile->name} (ID: {$playlistProfile->id}) has no max stream limit. Proceeding with source {$sourceChannelModel->id}.");
            }
            // --- Playlist Profile Stream Limit Check for HLS END ---

            Log::channel('ffmpeg')->info("HLS Failover: Attempting to start stream for new source {$currentSource['id']} ({$currentSource['title']}) at index {$currentSourceIndex} for {$failoverChannel->name}.");

            try {
                // Stop any potentially lingering process for a *different* source ID from a previous failover attempt for this *same* failover channel session
                if ($pid !== null && $sessionData['current_source_channel_id'] !== $currentSource['id']) {
                    Log::channel('ffmpeg')->info("HLS Failover: Stopping lingering FFmpeg (PID: {$pid}) for old source {$sessionData['current_source_channel_id']} before starting new one for {$currentSource['id']}.");
                    $this->hlsService->stopStream('channel', $sessionData['current_source_channel_id']);
                }

                $pid = $this->hlsService->startStream(
                    type: 'channel',
                    id: $currentSource['id'],
                    streamUrl: $currentSource['url'],
                    title: $currentSource['title'],
                    userAgent: $currentSource['user_agent'],
                    playlistProfileId: ($playlistProfile && $playlistProfile->is_active) ? $playlistProfile->id : null
                );
                Log::channel('ffmpeg')->info("HLS Failover: Successfully started FFmpeg (PID: {$pid}) for source {$currentSource['id']} for {$failoverChannel->name}, with Profile ID: " . (($playlistProfile && $playlistProfile->is_active) ? $playlistProfile->id : 'None'));
            } catch (Exception $e) {
                Log::channel('ffmpeg')->error("HLS Failover: Failed to start stream for source {$currentSource['id']} (Failover: {$failoverChannel->name}): " . $e->getMessage());
                // Add to bad source cache
                $badSourceCacheKeyFail = self::BAD_SOURCE_CACHE_PREFIX . $currentSource['id'];
                $cacheReason = "failed_hls_startup (" . $e->getMessage() . ")";
                Redis::setex($badSourceCacheKeyFail, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReason);
                Log::channel('ffmpeg')->info("HLS Failover: Added source ID {$currentSource['id']} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes due to HLS startStream failure. Reason: {$cacheReason}");
                $pid = null; // Ensure pid is null if startStream failed

                // Update session to reflect failure and allow quick retry of next source
                $sessionData = [
                    'sources_list' => $sources,
                    'current_source_index' => $currentSourceIndex, // Keep current index, next request will increment
                    'current_source_channel_id' => $currentSource['id'],
                    'current_ffmpeg_pid' => null // Crucial: set PID to null
                ];
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                // Instead of aborting, let the client retry. The next request will try the next source due to PID being null.
                // If all sources fail this way, it will eventually hit the "all sources exhausted" condition.
                // However, to immediately try the next source in the list without waiting for another client request:
                return $this->serveHlsPlaylist($request, $failoverChannel); // Recursive call to try next
            }
        }
        
        // Update session state (ensure $currentSource is not null if we skipped all)
        if ($currentSource === null) { // Should be caught by exhaustion check above, but as a safeguard
            Log::channel('ffmpeg')->error("HLS Failover: Current source is null before updating session for {$failoverChannel->name}. This should not happen.");
            Cache::forget($sessionCacheKey);
            abort(503, "HLS playlist generation failed due to an unexpected error in source selection.");
        }

        $sessionData = [
            'sources_list' => $sources,
            'current_source_index' => $currentSourceIndex,
            'current_source_channel_id' => $currentSource['id'],
            'current_ffmpeg_pid' => $pid
        ];
        Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
        Log::channel('ffmpeg')->info("HLS Failover: Session updated for {$failoverChannel->name}. Current source ID: {$currentSource['id']}, PID: {$pid}.");

        // Serve Playlist
        $playlistPath = Storage::disk('app')->path("hls/{$currentSource['id']}/stream.m3u8");
        $playlistReady = false;
        for ($i = 0; $i < 10; $i++) { // 10 attempts, 1 sec sleep
            if (file_exists($playlistPath) && filesize($playlistPath) > 0) {
                $playlistReady = true;
                break;
            }
            if (!$this->hlsService->isRunning('channel', $currentSource['id']) && $pid !== null) { // Ensure PID was set, meaning we tried to start it
                Log::channel('ffmpeg')->error("HLS Failover: FFmpeg process (PID: {$pid}) for source {$currentSource['id']} died while waiting for playlist for {$failoverChannel->name}.");
                // Add to bad source cache
                $badSourceCacheKeyDied = self::BAD_SOURCE_CACHE_PREFIX . $currentSource['id'];
                $cacheReasonDied = "failed_hls_process_died_waiting_playlist";
                Redis::setex($badSourceCacheKeyDied, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReasonDied);
                Log::channel('ffmpeg')->info("HLS Failover: Added source ID {$currentSource['id']} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes. Reason: {$cacheReasonDied}");

                // Clear PID from session so next request attempts failover by trying the next source
                $sessionData['current_ffmpeg_pid'] = null;
                // $sessionData['current_source_index'] remains, next call will increment it.
                Cache::put($sessionCacheKey, $sessionData, now()->addHours(6));
                // abort(503, "Playlist generation failed as stream ended prematurely. Please try again.");
                // Instead of aborting, make a recursive call to try the next source immediately.
                return $this->serveHlsPlaylist($request, $failoverChannel);
            }
            sleep(1);
        }

        if ($playlistReady) {
            Log::channel('ffmpeg')->info("HLS Failover: Playlist found for source {$currentSource['id']}. Serving to client for {$failoverChannel->name}. Path: {$playlistPath}");
            return response('', 200, [
                'Content-Type'      => 'application/vnd.apple.mpegurl',
                'X-Accel-Redirect'  => "/internal/hls/{$currentSource['id']}/stream.m3u8",
                'Cache-Control'     => 'no-cache, no-transform',
                'Connection'        => 'keep-alive',
            ]);
        } else { // Playlist not ready after timeout
            if (!$this->hlsService->isRunning('channel', $currentSource['id']) && $pid !== null) {
                Log::channel('ffmpeg')->error("HLS Failover: Playlist not found AND FFmpeg process (PID: {$pid}) for source {$currentSource['id']} is NOT running for {$failoverChannel->name}. Forcing re-evaluation after caching.");
                // Add to bad source cache
                $badSourceCacheKeyNotReadyDied = self::BAD_SOURCE_CACHE_PREFIX . $currentSource['id'];
                $cacheReasonNotReadyDied = "failed_hls_playlist_not_ready_process_died";
                Redis::setex($badSourceCacheKeyNotReadyDied, self::BAD_SOURCE_CACHE_MINUTES * 60, $cacheReasonNotReadyDied);
                Log::channel('ffmpeg')->info("HLS Failover: Added source ID {$currentSource['id']} to bad source cache for " . self::BAD_SOURCE_CACHE_MINUTES . " minutes. Reason: {$cacheReasonNotReadyDied}");
                
                // Clear the session to force a full re-evaluation, including re-fetching sources.
                // This is a strong measure for when a source seems definitively broken.
                Cache::forget($sessionCacheKey); 
                abort(404, "Playlist not found for current source and process died. Please retry the main channel URL.");
            } else if ($this->hlsService->isRunning('channel', $currentSource['id']) && $pid !== null) {
                Log::channel('ffmpeg')->warning("HLS Failover: Playlist for source {$currentSource['id']} not ready after 10s for {$failoverChannel->name}, but FFmpeg (PID: {$pid}) still running. Client should retry the playlist URL.");
                // Don't add to bad source cache here, as ffmpeg is still running, might be a temporary network issue for playlist segments.
                abort(503, "Playlist not ready, please try again shortly.");
            } else { // PID is null, means we never even started ffmpeg for this source (e.g. all prior sources cached)
                 Log::channel('ffmpeg')->error("HLS Failover: Playlist not found and no FFmpeg PID for source {$currentSource['id']}. This indicates an issue with source selection logic or prior failures.");
                 Cache::forget($sessionCacheKey);
                 abort(500, "Error in HLS stream setup.");
            }
        }
    }
}

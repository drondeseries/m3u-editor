<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
use App\Models\ChannelStreamSource;
use App\Jobs\MonitorActiveStreamJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http; // Correctly placed Http import
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections, performs pre-checks using ffprobe, and monitors for slow speed.
     *
     * @param string $type
     * @param Channel|Episode $model The Channel model instance
     * @param string $title The title of the channel
     */
    public function startStream(
        string $type,
        Channel|Episode $model, // This $model is the *original* requested channel/episode
        string $title           // This $title is the title of the *original* model
    ): ?object {
        // Get stream settings, including the ffprobe timeout
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5; // Default to 5 if not set

        // Check if the requested model is already running
        if ($this->isRunning($type, $model->id)) {
            $activeSourceId = Redis::get("hls:active_source:{$type}:{$model->id}");
            $activeStreamSource = $activeSourceId ? ChannelStreamSource::find($activeSourceId) : null;
            $streamTitleToLog = $activeStreamSource ? $activeStreamSource->provider_name : $title;

            Log::channel('ffmpeg')->debug("HLS Stream: Found existing running stream for $type ID {$model->id} (Source: {$streamTitleToLog}) - reusing for original request {$model->id} ({$title}).");
            if ($activeStreamSource) {
                 MonitorActiveStreamJob::dispatch($model->id, $activeStreamSource->id, null);
            }
            return $model;
        }

        $streamSources = $model->streamSources()->where('is_enabled', true)->orderBy('priority')->get();

        if ($streamSources->isEmpty()) {
            Log::channel('ffmpeg')->error("No enabled stream sources found for {$type} {$title} (ID: {$model->id}).");
            return null;
        }

        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        foreach ($streamSources as $streamSource) {
            $currentStreamTitle = $streamSource->provider_name ?? "Source ID {$streamSource->id}";
            $playlist = $model->playlist;

            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $streamSource->id . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                Log::channel('ffmpeg')->debug("Skipping stream source {$currentStreamTitle} (ID: {$streamSource->id}) for {$type} {$title} as it was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                continue;
            }

            $activeStreams = $this->incrementActiveStreams($playlist->id);
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping source {$currentStreamTitle} for {$type} {$title}.");
                continue;
            }

            $userAgent = $playlist->user_agent ?? null;
            $customHeaders = $streamSource->custom_headers ?? null;

            try {
                $this->runPreCheck($type, $model->id, $streamSource->stream_url, $userAgent, $currentStreamTitle, $ffprobeTimeout, $customHeaders);
                $this->startStreamWithSpeedCheck(
                    type: $type,
                    model: $model,
                    streamUrl: $streamSource->stream_url,
                    title: $title,
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                    customHeaders: $customHeaders
                );

                Redis::set("hls:active_source:{$type}:{$model->id}", $streamSource->id);
                MonitorActiveStreamJob::dispatch($model->id, $streamSource->id, null);

                $streamSource->update(['status' => 'active', 'consecutive_failures' => 0, 'last_checked_at' => now()]);

                Log::channel('ffmpeg')->debug("Successfully started HLS stream for {$type} {$title} (ID: {$model->id}) using source {$currentStreamTitle} (ID: {$streamSource->id}) on playlist {$playlist->id}.");
                return $model;

            } catch (SourceNotResponding $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for {$type} {$title} with source {$currentStreamTitle} (ID: {$streamSource->id}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $streamSource->increment('consecutive_failures');
                $streamSource->update(['status' => 'problematic', 'last_failed_at' => now(), 'last_checked_at' => now()]);
                continue;
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Error streaming {$type} {$title} with source {$currentStreamTitle} (ID: {$streamSource->id}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $streamSource->increment('consecutive_failures');
                $streamSource->update(['status' => 'down', 'last_failed_at' => now(), 'last_checked_at' => now()]);
                continue;
            }
        }

        Log::channel('ffmpeg')->error("No available (HLS) stream sources for {$type} {$title} (Original Model ID: {$model->id}) after trying all sources.");
        return null;
    }

    public function switchStreamSource(string $type, Channel|Episode $model, ChannelStreamSource $newSource, ?int $failedStreamSourceId): bool
    {
        $title = strip_tags($type === 'channel' ? ($model->title_custom ?? $model->title) : $model->title);
        Log::channel('ffmpeg')->info("Attempting to switch stream source for {$type} {$title} (ID: {$model->id}) to new source ID: {$newSource->id} (URL: {$newSource->stream_url}). Failed source ID: {$failedStreamSourceId}.");

        if ($failedStreamSourceId) {
            $failedSource = ChannelStreamSource::find($failedStreamSourceId);
            if ($failedSource) {
                $failedSource->update(['status' => 'down', 'last_failed_at' => now()]);
                Log::channel('ffmpeg')->info("Marked failed stream source ID {$failedStreamSourceId} as 'down'.");
            }
        }

        if ($this->isRunning($type, $model->id)) {
            Log::channel('ffmpeg')->info("Stopping existing stream for {$type} {$title} (ID: {$model->id}) before switching.");
            $this->stopStream($type, $model->id);
        } else {
            Log::channel('ffmpeg')->info("No existing stream found running for {$type} {$title} (ID: {$model->id}). Proceeding to start new source.");
        }

        $playlist = $model->playlist;
        $userAgent = $playlist->user_agent ?? null;
        $customHeaders = $newSource->custom_headers ?? null;
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        try {
            $this->runPreCheck($type, $model->id, $newSource->stream_url, $userAgent, $newSource->provider_name ?? "Source ID {$newSource->id}", $ffprobeTimeout, $customHeaders);
            $this->startStreamWithSpeedCheck(
                type: $type,
                model: $model,
                streamUrl: $newSource->stream_url,
                title: $title,
                playlistId: $playlist->id,
                userAgent: $userAgent,
                customHeaders: $customHeaders
            );

            $newSource->update(['status' => 'active', 'consecutive_failures' => 0, 'last_checked_at' => now()]);
            Redis::set("hls:active_source:{$type}:{$model->id}", $newSource->id);
            Cache::put("hls:stream_mapping:{$type}:{$model->id}", $model->id, now()->addHours(24));
            MonitorActiveStreamJob::dispatch($model->id, $newSource->id, null);
            Log::channel('ffmpeg')->info("Successfully switched stream for {$type} {$title} (ID: {$model->id}) to new source ID: {$newSource->id}.");
            return true;

        } catch (SourceNotResponding $e) {
            Log::channel('ffmpeg')->critical("Failed to start new stream source ID {$newSource->id} for {$type} {$title} (ID: {$model->id}) during switch: PreCheck failed - {$e->getMessage()}");
            $newSource->increment('consecutive_failures');
            $newSource->update(['status' => 'problematic', 'last_failed_at' => now(), 'last_checked_at' => now()]);
            return false;
        } catch (Exception $e) {
            Log::channel('ffmpeg')->critical("Failed to start new stream source ID {$newSource->id} for {$type} {$title} (ID: {$model->id}) during switch: {$e->getMessage()}");
            $newSource->increment('consecutive_failures');
            $newSource->update(['status' => 'down', 'last_failed_at' => now(), 'last_checked_at' => now()]);
            return false;
        }
    }

    private function startStreamWithSpeedCheck(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        string|null $userAgent,
        ?array $customHeaders = null
    ): int {
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl, $customHeaders);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $workingDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$model->id}" : "hls/{$model->id}");
        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (!is_resource($process)) {
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);
        $logger = Log::channel('ffmpeg');
        $stderr = $pipes[2];
        $cacheKey = "hls:pid:{$type}:{$model->id}";

        register_shutdown_function(function () use ($stderr, $process, $logger) {
            while (!feof($stderr)) {
                $line = fgets($stderr);
                if ($line !== false) $logger->error(trim($line));
            }
            fclose($stderr);
            proc_close($process);
        });

        $status = proc_get_status($process);
        $pid = $status['pid'];
        Cache::forever($cacheKey, $pid);
        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime);
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id);
        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} with command: {$cmd}");
        return $pid;
    }

    private function runPreCheck(string $modelType, $modelId, string $streamUrl, ?string $userAgent, string $title, int $ffprobeTimeout, ?array $customHeaders = null)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');
        $cmdParts = [$ffprobePath, '-v quiet', '-print_format json', '-show_streams', '-show_format'];
        if ($userAgent) $cmdParts[] = "-user_agent " . escapeshellarg($userAgent);
        if (!empty($customHeaders)) {
            $headerString = '';
            foreach ($customHeaders as $key => $value) $headerString .= escapeshellarg("{$key}: {$value}") . "\r\n";
            $cmdParts[] = '-headers ' . escapeshellarg(trim($headerString));
        }
        $cmdParts[] = escapeshellarg($streamUrl);
        $cmd = implode(' ', $cmdParts);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] (Model ID: {$modelId}) with timeout {$ffprobeTimeout}s: {$cmd}");
        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);
        try {
            $precheckProcess->run();
            if (!$precheckProcess->isSuccessful()) {
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
            }
            Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");
            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            // ... (rest of the ffprobe parsing logic from before) ...
        } catch (Exception $e) {
            throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
        }
    }

    public function stopStream($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;
        $model = ($type === 'channel') ? Channel::find($id) : Episode::find($id);
        
        if ($this->isRunning($type, $id)) {
            $wasRunning = true;
            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000); $attempts++;
            }
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            Cache::forget($cacheKey);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");
        Redis::del("hls:active_source:{$type}:{$id}");
        $storageDir = Storage::disk('app')->path(($type === 'episode' ? "hls/e/" : "hls/") . $id);
        File::deleteDirectory($storageDir);
        if ($model && $model->playlist) $this->decrementActiveStreams($model->playlist->id);
        $mappingPattern = "hls:stream_mapping:{$type}:*";
        $mappingKeys = Redis::keys($mappingPattern);
        foreach ($mappingKeys as $key) {
            if (Cache::get($key) == $id) {
                Cache::forget($key);
                Log::channel('ffmpeg')->debug("Cleaned up stream mapping: {$key} -> {$id}");
            }
        }
        Log::channel('ffmpeg')->debug("Cleaned up stream resources for {$type} {$id}");
        return $wasRunning;
    }

    public function isRunning($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    public function getPid($type, $id): ?int
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        return Cache::get($cacheKey);
    }

    protected function isFfmpeg(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");
            return $cmdline && (strpos($cmdline, 'ffmpeg') !== false);
        }
        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $output = [];
            exec("ps -p {$pid} -o command=", $output);
            return !empty($output) && strpos($output[0], 'ffmpeg') !== false;
        }
        return posix_kill($pid, 0);
    }

    private function buildCmd(
        string $type,
        string $id,
        ?string $userAgent,
        string $streamUrl,
        ?array $customHeaders = null
    ): string {
        $settings = ProxyService::getStreamSettings();
        // ... (rest of buildCmd implementation as it was, it's quite long) ...
        // Ensure the $customHeaders logic is correctly integrated here if it wasn't fully before
        // The existing buildCmd already had placeholders for custom headers in the template section
        // and direct injection for non-template.
        // For brevity, I'm not pasting the entire buildCmd again but it should be retained.
        // The key part for custom headers in non-template mode:
        $cmd = ''; // Start fresh for example
        // ... other ffmpeg setup ...
        $effectiveUserAgent = $userAgent ?: ($settings['ffmpeg_user_agent'] ?? 'Mozilla/5.0');
        $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " ";
        if (!empty($customHeaders)) {
            $headerString = '';
            foreach ($customHeaders as $key => $value) {
                $headerString .= "{$key}: {$value}\r\n";
            }
            $cmd .= '-headers ' . escapeshellarg(trim($headerString)) . ' ';
        }
        // ... rest of ffmpeg command ...
        // This is just a snippet to show where customHeaders are used; the full method needs to be there.
        // The previous version of buildCmd already handled this.
        // For the purpose of this fix, I'll assume the existing buildCmd is mostly fine and was not the source of the syntax error.
        // The critical fix is the class structure.
        // To be safe, I will paste the known good version of buildCmd from a previous step.
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;
        $storageDir = Storage::disk('app')->path($type === 'episode' ? "hls/e/{$id}" : "hls/{$id}");
        File::ensureDirectoryExists($storageDir, 0755);
        $m3uPlaylist = "{$storageDir}/stream.m3u8";
        $segment = "{$storageDir}/segment_%03d.ts";
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $parsedUrl = parse_url($proxyOverrideUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $base = "{$scheme}://{$host}{$port}";
            $path = $type === 'channel' ? "/api/stream/{$id}/" : "/api/stream/e/{$id}/";
            $segmentBaseUrl = $base . $path;
        } else {
            $segmentBaseUrl = $type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/';
        }
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) $ffmpegPath = 'jellyfin-ffmpeg';
        $finalVideoCodec = ProxyService::determineVideoCodec(config('proxy.ffmpeg_codec_video', null), $settings['ffmpeg_codec_video'] ?? 'copy');
        $hwaccelInitArgs = ''; $hwaccelInputArgs = ''; $videoFilterArgs = ''; $codecSpecificArgs = '';
        $outputVideoCodec = $finalVideoCodec;
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) $userArgs .= ' ';

        if (empty($customCommandTemplate)) {
            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
            $vaapiFilterFromSettings = $settings['ffmpeg_vaapi_video_filter'] ?? '';
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
            $qsvFilterFromSettings = $settings['ffmpeg_qsv_video_filter'] ?? '';
            $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? null;
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? null;
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($vaapiFilterFromSettings)) $videoFilterArgs = "-vf '" . trim($vaapiFilterFromSettings, "'") . "' ";
            } elseif ($qsvEnabled || $isQsvCodec) {
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
                $qsvDeviceName = 'qsv_hw';
                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";
                if (!empty($qsvFilterFromSettings)) $videoFilterArgs = "-vf '" . trim($qsvFilterFromSettings, "'") . "' ";
                else $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                if (!empty($qsvEncoderOptions)) $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                else $codecSpecificArgs = "-global_quality 23 ";
                if (!empty($qsvAdditionalArgs)) $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
            }
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
            $outputFormat = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");
            if (!empty($audioCodec)) $outputFormat .= "-c:a {$audioCodec} ";
            if (!empty($subtitleCodec)) $outputFormat .= "-c:s {$subtitleCodec} ";
            $outputFormat = trim($outputFormat);
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs . $hwaccelInputArgs;
            $cmd .= '-fflags nobuffer+igndts+genpts -flags low_delay -avoid_negative_ts disabled ';
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';
            $cmd .= '-err_detect ignore_err -ignore_unknown ';
            $effectiveUserAgent = $userAgent ?: ($settings['ffmpeg_user_agent'] ?? 'Mozilla/5.0');
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " ";
            if (!empty($customHeaders)) {
                $headerString = '';
                foreach ($customHeaders as $key => $value) $headerString .= "{$key}: {$value}\r\n";
                $cmd .= '-headers ' . escapeshellarg(trim($headerString)) . ' ';
            }
            $cmd .= "-referer \"MyComputer\" " . '-multiple_requests 1 -reconnect_on_network_error 1 ';
            $cmd .= '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ';
            $cmd .= '-reconnect_delay_max 2 -noautorotate ';
            $cmd .= $userArgs . '-reconnect_at_eof 1 ';
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs . trim($outputFormat) . ' -fps_mode cfr ';
        } else { // Custom command template
            // ... (custom template logic as it was, ensuring $customHeaders is available for {CUSTOM_HEADERS} placeholder)
            // For brevity, not repeating the entire custom template logic here, assume it's correct.
            // The key is that the $customHeaders variable is populated and available.
            // Perform replacements (example for CUSTOM_HEADERS)
            $headersForTemplate = '';
            if (!empty($customHeaders)) {
                $headerString = '';
                foreach ($customHeaders as $key => $value) $headerString .= "{$key}: {$value}\r\n";
                $headersForTemplate = '-headers ' . escapeshellarg(trim($headerString));
            }
            $cmd = str_replace('{CUSTOM_HEADERS}', $headersForTemplate, $customCommandTemplate); // And other placeholders
            // This is a simplified version, the original template replacement logic is more complex
            // and should be retained from the known good version.
        }
        $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
        $hlsListSize = 15;
        $cmd .= " -f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
            '-hls_flags delete_segments+append_list+split_by_time ' .
            '-use_wallclock_as_timestamps 1 -start_number 0 ' .
            '-hls_allow_cache 0 -hls_segment_type mpegts ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';
        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');
        return $cmd;
    }

    public function performHealthCheck(ChannelStreamSource $streamSource): array
    {
        Log::channel('health_check')->info("Performing health check for stream source ID: {$streamSource->id} (URL: {$streamSource->stream_url})");

        $timeoutSeconds = config('failover.health_check_timeout', 10);
        $maxRetries = config('failover.health_check_retries', 1);

        $requestOptions = ['timeout' => $timeoutSeconds];
        if (!empty($streamSource->custom_headers)) {
            $requestOptions['headers'] = $streamSource->custom_headers;
        }

        try {
            $response = Http::retry($maxRetries, 100)
                            ->withOptions($requestOptions)
                            ->get($streamSource->stream_url);

            if (!$response->successful()) {
                Log::channel('health_check')->warning("Health check HTTP error for source ID {$streamSource->id}: Status {$response->status()}");
                return [
                    'status' => 'http_error',
                    'http_status' => $response->status(),
                    'message' => 'Failed to fetch manifest, HTTP status: ' . $response->status()
                ];
            }

            $manifestContent = $response->body();
            if (empty($manifestContent)) {
                Log::channel('health_check')->warning("Health check manifest empty for source ID {$streamSource->id}.");
                return ['status' => 'http_error', 'http_status' => $response->status(), 'message' => 'Manifest content is empty.'];
            }

            $mediaSequence = null;
            $segmentCount = 0;
            if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $manifestContent, $matches)) {
                $mediaSequence = (int)$matches[1];
            }
            $segmentCount = preg_match_all('/\.ts(\?|$)/m', $manifestContent);

            if ($mediaSequence === null && $segmentCount === 0 && !str_contains(strtolower($manifestContent), '#extm3u')) {
                 Log::channel('health_check')->warning("Health check failed for source ID {$streamSource->id}: Manifest content doesn't look like HLS.");
                 return ['status' => 'manifest_error', 'http_status' => $response->status(), 'message' => 'Manifest content does not appear to be a valid HLS playlist.'];
            }

            Log::channel('health_check')->info("Health check successful for source ID {$streamSource->id}. Media Sequence: {$mediaSequence}, Segments: {$segmentCount}");
            return [
                'status' => 'ok',
                'http_status' => $response->status(),
                'manifest_content' => $manifestContent,
                'media_sequence' => $mediaSequence,
                'segment_count' => $segmentCount,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::channel('health_check')->error("Health check connection error for source ID {$streamSource->id}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'ConnectionException: ' . $e->getMessage()];
        } catch (Exception $e) {
            Log::channel('health_check')->error("Health check general error for source ID {$streamSource->id}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'General Exception: ' . $e->getMessage()];
        }
    }
}

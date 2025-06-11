<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Traits\TracksActiveStreams;
// Removed: use App\Models\ChannelStreamSource;
use App\Jobs\MonitorActiveStreamJob; // MonitorActiveStreamJob constructor will need update later
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    public function startStream(
        string $type,
        Channel|Episode $model,
        string $title
    ): ?object {
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;

        if ($this->isRunning($type, $model->id)) {
            $activeUrl = Redis::get("hls:active_url:{$type}:{$model->id}");
            Log::channel('ffmpeg')->debug("HLS Stream: Found existing running stream for $type ID {$model->id} (URL: {$activeUrl}) - reusing for original request {$model->id} ({$title}).");
            if ($activeUrl) {
                 MonitorActiveStreamJob::dispatch($model->id, $activeUrl, $type, null);
            }
            return $model;
        }

        $urlsToTry = [];
        $primaryUrl = ($type === 'channel') ? ($model->url_custom ?? $model->url) : $model->url;
        if ($primaryUrl) {
            $urlsToTry[] = ['url' => $primaryUrl, 'title_desc' => $title . " (Primary)"];
        }

        if ($type === 'channel' && method_exists($model, 'failoverChannels')) {
            $failoverChannels = $model->failoverChannels ?? [];
            foreach ($failoverChannels as $failoverChannel) {
                $url = $failoverChannel->url_custom ?? $failoverChannel->url;
                if ($url) {
                    $urlsToTry[] = ['url' => $url, 'title_desc' => ($failoverChannel->title_custom ?? $failoverChannel->title) . " (Failover for Channel ID {$model->id})"];
                }
            }
        }

        if (empty($urlsToTry)) {
            Log::channel('ffmpeg')->error("No stream URLs (primary or failover) found for {$type} {$title} (ID: {$model->id}).");
            return null;
        }

        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        $playlist = $model->playlist;
        if (!$playlist) {
            Log::channel('ffmpeg')->error("Playlist not found for {$type} {$title} (ID: {$model->id}). Cannot proceed.");
            Redis::srem("hls:active_{$type}_ids", $model->id);
            return null;
        }
        $userAgent = $playlist->user_agent ?? null;

        foreach ($urlsToTry as $streamData) {
            $currentUrl = $streamData['url'];
            $currentStreamLogTitle = $streamData['title_desc'];

            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . md5($currentUrl) . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                Log::channel('ffmpeg')->debug("Skipping URL {$currentUrl} for {$type} '{$title}' as it was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                continue;
            }

            $activeStreams = $this->incrementActiveStreams($playlist->id);
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping URL {$currentUrl} for {$type} '{$title}'.");
                continue;
            }

            $customHeaders = null;

            try {
                $this->runPreCheck($type, $model->id, $currentUrl, $userAgent, $currentStreamLogTitle, $ffprobeTimeout, $customHeaders);

                $this->startStreamWithSpeedCheck(
                    type: $type,
                    model: $model,
                    streamUrl: $currentUrl,
                    title: $title,
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                    customHeaders: $customHeaders
                );

                Redis::set("hls:active_url:{$type}:{$model->id}", $currentUrl);
                MonitorActiveStreamJob::dispatch($model->id, $currentUrl, $type, null /* userId */);

                Log::channel('ffmpeg')->debug("Successfully started HLS stream for {$type} '{$title}' (ID: {$model->id}) using URL {$currentUrl}.");
                return $model;

            } catch (SourceNotResponding $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for {$type} '{$title}' with URL {$currentUrl}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                continue;
            } catch (Exception $e) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Error streaming {$type} '{$title}' with URL {$currentUrl}: " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                continue;
            }
        }

        Log::channel('ffmpeg')->error("No available (HLS) URLs for {$type} '{$title}' (Original Model ID: {$model->id}) after trying all sources.");
        Redis::srem("hls:active_{$type}_ids", $model->id);
        return null;
    }

    public function switchToNextAvailableUrl(string $type, Channel|Episode $model, string $failedUrl): bool
    {
        $title = strip_tags($type === 'channel' ? ($model->title_custom ?? $model->title) : $model->title);
        Log::channel('ffmpeg')->info("Attempting to switch stream for {$type} '{$title}' (ID: {$model->id}) from failed URL: {$failedUrl}.");

        if ($this->isRunning($type, $model->id)) {
            Log::channel('ffmpeg')->info("Stopping existing stream for {$type} '{$title}' (ID: {$model->id}) before switching.");
            $this->stopStream($type, $model->id);
        } else {
            Log::channel('ffmpeg')->info("No existing stream found running for {$type} '{$title}' (ID: {$model->id}). Proceeding to find next available URL.");
        }

        $urlsToTry = [];
        $primaryUrl = ($type === 'channel') ? ($model->url_custom ?? $model->url) : $model->url;
        if ($primaryUrl) {
            $urlsToTry[] = ['url' => $primaryUrl, 'title_desc' => $title . " (Primary)"];
        }

        if ($type === 'channel' && method_exists($model, 'failoverChannels')) {
            $failoverChannels = $model->failoverChannels ?? [];
            foreach ($failoverChannels as $failoverChannel) {
                $url = $failoverChannel->url_custom ?? $failoverChannel->url;
                if ($url) {
                     $urlsToTry[] = ['url' => $url, 'title_desc' => ($failoverChannel->title_custom ?? $failoverChannel->title) . " (Failover for Channel ID {$model->id})"];
                }
            }
        }

        if (empty($urlsToTry)) {
            Log::channel('ffmpeg')->error("No URLs (primary or failover) available for {$type} '{$title}' (ID: {$model->id}) to switch to.");
            return false;
        }

        $failedUrlIndex = -1;
        foreach ($urlsToTry as $index => $urlData) {
            if ($urlData['url'] === $failedUrl) {
                $failedUrlIndex = $index;
                break;
            }
        }

        $startIndex = ($failedUrlIndex === -1 || $failedUrlIndex === count($urlsToTry) - 1) ? 0 : $failedUrlIndex + 1;

        $playlist = $model->playlist;
         if (!$playlist) {
            Log::channel('ffmpeg')->error("Playlist not found for {$type} '{$title}' (ID: {$model->id}) during switch. Cannot proceed.");
            return false;
        }
        $userAgent = $playlist->user_agent ?? null;
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5;
        $customHeaders = null;

        for ($i = 0; $i < count($urlsToTry); $i++) {
            $currentIndex = ($startIndex + $i) % count($urlsToTry);
            $urlData = $urlsToTry[$currentIndex];
            $currentUrl = $urlData['url'];
            $currentStreamLogTitle = $urlData['title_desc'];

            if ($currentUrl === $failedUrl && $i === 0 && $failedUrlIndex !== -1 && count($urlsToTry) > 1) {
                 if (($startIndex + $i + 1) % count($urlsToTry) !== $failedUrlIndex ) {
                     continue;
                }
            }

            Log::channel('ffmpeg')->info("Attempting next URL for {$type} '{$title}': {$currentUrl}");
            try {
                $this->runPreCheck($type, $model->id, $currentUrl, $userAgent, $currentStreamLogTitle, $ffprobeTimeout, $customHeaders);
                $this->startStreamWithSpeedCheck(
                    type: $type,
                    model: $model,
                    streamUrl: $currentUrl,
                    title: $title,
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                    customHeaders: $customHeaders
                );

                Redis::set("hls:active_url:{$type}:{$model->id}", $currentUrl);
                MonitorActiveStreamJob::dispatch($model->id, $currentUrl, $type, null);
                Log::channel('ffmpeg')->info("Successfully switched stream for {$type} '{$title}' (ID: {$model->id}) to new URL: {$currentUrl}.");
                return true;

            } catch (SourceNotResponding $e) {
                Log::channel('ffmpeg')->warning("Failed to start new URL {$currentUrl} for {$type} '{$title}' (ID: {$model->id}) during switch: PreCheck failed - {$e->getMessage()}");
                 $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . md5($currentUrl) . ':' . $playlist->id;
                 Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
            } catch (Exception $e) {
                Log::channel('ffmpeg')->warning("Failed to start new URL {$currentUrl} for {$type} '{$title}' (ID: {$model->id}) during switch: {$e->getMessage()}");
                 $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . md5($currentUrl) . ':' . $playlist->id;
                 Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
            }
        }

        Log::channel('ffmpeg')->critical("All available URLs failed for {$type} '{$title}' (ID: {$model->id}) after trying to switch from {$failedUrl}.");
        return false;
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
        $logger = Log::channel('ffmpeg'); // Reverted to Log::channel('ffmpeg')
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
            // Further processing of ffprobeJsonOutput can be done here if needed
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
        Redis::del("hls:active_url:{$type}:{$id}");
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
        } else {
            $cmd = $customCommandTemplate;
            // ... (ensure all placeholders including {CUSTOM_HEADERS} are replaced correctly)
             $hwaccelInitArgsValue = ''; $hwaccelArgsValue = ''; $videoFilterArgsValue = '';
             $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
             $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';
             $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi'); $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi' || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
            } elseif (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv' || $isQsvCodec) {
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
                 if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
            }

            $audioCodecForTemplate = (config('proxy.ffmpeg_codec_audio') ?: ($settings['ffmpeg_codec_audio'] ?? null)) ?: 'copy';
            $subtitleCodecForTemplate = (config('proxy.ffmpeg_codec_subtitles') ?: ($settings['ffmpeg_codec_subtitles'] ?? null)) ?: 'copy';
            $outputCommandSegment = "-c:v {$outputVideoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . "-c:a {$audioCodecForTemplate} -c:s {$subtitleCodecForTemplate}";
            $videoCodecArgs = "-c:v {$outputVideoCodec}" . ($codecSpecificArgs ? " " . trim($codecSpecificArgs) : "");
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $effectiveUserAgentForTemplate = $userAgent ?: ($settings['ffmpeg_user_agent'] ?? 'Mozilla/5.0');
            $cmd = str_replace('{USER_AGENT}', escapeshellarg($effectiveUserAgentForTemplate), $cmd);
            $headersForTemplate = '';
            if (!empty($customHeaders)) {
                $headerString = '';
                foreach ($customHeaders as $key => $value) $headerString .= "{$key}: {$value}\r\n";
                $headersForTemplate = '-headers ' . escapeshellarg(trim($headerString));
            }
            $cmd = str_replace('{CUSTOM_HEADERS}', $headersForTemplate, $cmd);
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd);
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

    public function performHealthCheck(string $url, ?array $customHeaders = null, ?string $userAgent = null): array
    {
        Log::info("Performing health check for URL: {$url}");

        $timeoutSeconds = config('failover.health_check_timeout', 10);
        $maxRetries = config('failover.health_check_retries', 1);

        $request = Http::retry($maxRetries, 100)->timeout($timeoutSeconds);

        if (!empty($customHeaders)) {
            $finalHeaders = $customHeaders;
            if ($userAgent && !isset($finalHeaders['User-Agent']) && !isset($finalHeaders['user-agent'])) {
                 $finalHeaders['User-Agent'] = $userAgent;
            }
            $request->withHeaders($finalHeaders);
        } elseif ($userAgent) {
            $request->withUserAgent($userAgent);
        }

        try {
            $response = $request->get($url);

            if (!$response->successful()) {
                Log::warning("Health check HTTP error for URL {$url}: Status {$response->status()}");
                return [
                    'status' => 'http_error',
                    'http_status' => $response->status(),
                    'message' => 'Failed to fetch manifest, HTTP status: ' . $response->status()
                ];
            }

            $manifestContent = $response->body();
            if (empty($manifestContent)) {
                Log::warning("Health check manifest empty for URL {$url}.");
                return ['status' => 'http_error', 'http_status' => $response->status(), 'message' => 'Manifest content is empty.'];
            }

            $mediaSequence = null;
            $segmentCount = 0;
            if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $manifestContent, $matches)) {
                $mediaSequence = (int)$matches[1];
            }
            $segmentCount = preg_match_all('/\.ts(\?|$)/m', $manifestContent);

            if ($mediaSequence === null && $segmentCount === 0 && !str_contains(strtolower($manifestContent), '#extm3u')) {
                 Log::warning("Health check failed for URL {$url}: Manifest content doesn't look like HLS.");
                 return ['status' => 'manifest_error', 'http_status' => $response->status(), 'message' => 'Manifest content does not appear to be a valid HLS playlist.'];
            }

            Log::info("Health check successful for URL {$url}. Media Sequence: {$mediaSequence}, Segments: {$segmentCount}");
            return [
                'status' => 'ok',
                'http_status' => $response->status(),
                'manifest_content' => $manifestContent,
                'media_sequence' => $mediaSequence,
                'segment_count' => $segmentCount,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("Health check connection error for URL {$url}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'ConnectionException: ' . $e->getMessage()];
        } catch (Exception $e) {
            Log::error("Health check general error for URL {$url}: " . $e->getMessage());
            return ['status' => 'connection_error', 'message' => 'General Exception: ' . $e->getMessage()];
        }
    }
}

[end of app/Services/HlsStreamService.php]

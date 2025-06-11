<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;
use App\Services\HlsStreamService;
use App\Traits\TracksActiveStreams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Class HlsStreamController
 * 
 * This controller handles the HLS streaming for channels.
 * It manages the starting of the FFmpeg process and serves the HLS playlist and segments.
 */
class HlsStreamController extends Controller
{
    use TracksActiveStreams;

    private HlsStreamService $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
    }

    /**
     * Serves the HLS playlist for a channel.
     */
    public function serveChannelPlaylist(Request $request, string $encodedId): \Illuminate\Http\Response
    {
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // Ensure proper base64 padding
        }
        $channelId = base64_decode($encodedId);
        $channel = Channel::find($channelId);

        if (!$channel) {
            Log::error("HLS Playlist Request: Channel not found for encoded ID {$encodedId} (decoded: {$channelId}). IP: {$request->ip()}. Aborting.");
            abort(404, 'Channel not found.');
        }

        // Use original channel name for logging throughout this request, even if $channel object is reassigned
        $originalChannelName = strip_tags($channel->title_custom ?? $channel->title);
        Log::info("HLS Playlist Request: Received for channel {$channel->id} ('{$originalChannelName}'). Current status: {$channel->stream_status}, current_provider_id: {$channel->current_stream_provider_id}. IP: {$request->ip()}");

        return $this->servePlaylist($channel, $originalChannelName);
    }

    /**
     * Serves an HLS segment for a channel.
     */
    public function serveChannelSegment(Request $request, int $channelId, string $segment): \Illuminate\Http\Response
    {
        Log::debug("HLS Segment Request: Received for channel {$channelId}, segment {$segment}. IP: {$request->ip()}");
        $channel = Channel::find($channelId);
        if (!$channel) {
            Log::error("HLS Segment Request: Channel {$channelId} not found for segment {$segment}. Aborting. IP: {$request->ip()}");
            abort(404, "Channel not found for segment request.");
        }
        $channelName = strip_tags($channel->title_custom ?? $channel->title);
        return $this->serveSegment('channel', $channel, $segment, $channelName);
    }

    /**
     * Serves the HLS playlist for an episode.
     */
    public function serveEpisodePlaylist(Request $request, string $encodedId): \Illuminate\Http\Response
    {
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '==';
        }
        $episodeId = base64_decode($encodedId);
        $episode = Episode::find($episodeId);

        if (!$episode) {
            Log::error("HLS Playlist Request: Episode not found for encoded ID {$encodedId} (decoded: {$episodeId}). IP: {$request->ip()}. Aborting.");
            abort(404, 'Episode not found.');
        }
        $episodeTitle = strip_tags($episode->title);
        Log::info("HLS Playlist Request: Received for episode {$episode->id} ('{$episodeTitle}'). IP: {$request->ip()}");
        return $this->serveLegacyPlaylist('episode', $encodedId, $episode, $episodeTitle);
    }

    /**
     * Serves an HLS segment for an episode.
     */
    public function serveEpisodeSegment(Request $request, int $episodeId, string $segment): \Illuminate\Http\Response
    {
        Log::debug("HLS Segment Request: Received for episode {$episodeId}, segment {$segment}. IP: {$request->ip()}");
        $episode = Episode::find($episodeId);
        if (!$episode) {
             Log::error("HLS Segment Request: Episode {$episodeId} not found for segment {$segment}. Aborting. IP: {$request->ip()}");
            abort(404, "Episode not found for segment request.");
        }
        $episodeTitle = strip_tags($episode->title);
        return $this->serveSegment('episode', $episode, $segment, $episodeTitle);
    }

    /**
     * Core logic to serve HLS playlist for a channel.
     */
    private function servePlaylist(Channel $channel, string $originalChannelName): \Illuminate\Http\Response
    {
        $channelId = $channel->id; // Store for logging in case $channel becomes null
        Log::info("HLS Playlist Logic: Processing channel {$channelId} ('{$originalChannelName}').");

        $m3u8Path = Storage::disk('app')->path("hls/{$channelId}/stream.m3u8");
        $nginxRedirectPath = "/internal/hls/{$channelId}/stream.m3u8";

        // Initial state check
        if ($channel->stream_status === 'failed') {
            Log::warning("HLS Playlist Logic: Channel {$channelId} ('{$originalChannelName}') initial status is 'failed'. Aborting with 503.");
            abort(503, 'Stream is currently unavailable (status: failed).');
        }

        $isRunning = $this->hlsService->isRunning('channel', $channelId);
        Log::debug("HLS Playlist Logic: Channel {$channelId} ('{$originalChannelName}'). Initial FFmpeg running: " . ($isRunning ? 'yes' : 'no') . ", M3U8 path: {$m3u8Path}, Initial Channel Status: {$channel->stream_status}, Provider: {$channel->current_stream_provider_id}.");

        // Scenario 1: Stream is expected to be running ('playing' or 'switching')
        if (($channel->stream_status === 'playing' || $channel->stream_status === 'switching') && $channel->current_stream_provider_id) {
            if ($isRunning && file_exists($m3u8Path)) {
                Log::info("HLS Playlist Logic: Channel {$channelId} ('{$originalChannelName}') is '{$channel->stream_status}', provider {$channel->current_stream_provider_id} process is running, M3U8 exists. Serving playlist.");
                return $this->createHlsResponse($nginxRedirectPath);
            } else {
                Log::warning("HLS Playlist Logic: Channel {$channelId} ('{$originalChannelName}') status '{$channel->stream_status}' with provider {$channel->current_stream_provider_id}, but process/M3U8 not found (isRunning: " . ($isRunning ? 'yes' : 'no') . ", m3u8_exists: " . (file_exists($m3u8Path) ? 'yes' : 'no') . "). Calling HlsStreamService->startStream for potential restart.");
                $channel = $this->hlsService->startStream($channel);

                if (!$channel) {
                    Log::error("HLS Playlist Logic: HlsStreamService->startStream (restart attempt) failed for channel ID {$channelId} ('{$originalChannelName}'). Service returned null. Aborting with 503.");
                    abort(503, 'Stream unavailable, restart failed.');
                } elseif ($channel->stream_status === 'failed') {
                     Log::error("HLS Playlist Logic: HlsStreamService->startStream (restart attempt) returned 'failed' status for channel {$channel->id} ('{$originalChannelName}'). Final status: {$channel->stream_status}. Aborting with 503.");
                     abort(503, 'Stream unavailable, restart failed.');
                }
                 Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$originalChannelName}') processed by startStream (restart attempt). New status: {$channel->stream_status}, provider: {$channel->current_stream_provider_id}. Proceeding to M3U8 wait loop.");
            }
        }
        // Scenario 2: Stream needs to be started (status is null, 'stopped', or was 'failed' and is being retried by new request)
        else {
            Log::info("HLS Playlist Logic: Channel {$channelId} ('{$originalChannelName}') needs explicit start or status is '{$channel->stream_status}'. Calling HlsStreamService->startStream.");
            $channel = $this->hlsService->startStream($channel);
            if (!$channel) {
                Log::error("HLS Playlist Logic: HlsStreamService->startStream (initial start) failed for channel ID {$channelId} ('{$originalChannelName}'). Service returned null. Aborting with 503.");
                 abort(503, 'Stream unavailable, failed to start.');
            } elseif ($channel->stream_status === 'failed') {
                Log::error("HLS Playlist Logic: HlsStreamService->startStream (initial start) returned 'failed' status for channel {$channel->id} ('{$originalChannelName}'). Final status: {$channel->stream_status}. Aborting with 503.");
                abort(503, 'Stream unavailable, failed to start.');
            }
            Log::info("HLS Playlist Logic: Channel {$channel->id} ('{$originalChannelName}') processed by startStream (initial start). Status: {$channel->stream_status}, provider: {$channel->current_stream_provider_id}. Proceeding to M3U8 wait loop.");
        }

        // Wait for M3U8 file to be created by FFmpeg
        $settings = app(GeneralSettings::class);
        $maxAttempts = $settings->hls_playlist_max_attempts ?? 15;
        $sleepSeconds = $settings->hls_playlist_sleep_seconds ?? 1.0;
        Log::debug("HLS Playlist Logic: Entering M3U8 wait loop for channel {$channel->id} ('{$originalChannelName}'). Max attempts: {$maxAttempts}, Sleep: {$sleepSeconds}s.");

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (file_exists($m3u8Path)) {
                Log::info("HLS Playlist Logic: M3U8 found for channel {$channel->id} ('{$originalChannelName}') on attempt {$attempt}. Serving.");
                return $this->createHlsResponse($nginxRedirectPath);
            }

            if (!$this->hlsService->isRunning('channel', $channel->id)) {
                Log::error("HLS Playlist Logic: FFmpeg process for channel {$channel->id} ('{$originalChannelName}') died while waiting for M3U8 (attempt {$attempt}). Aborting with 503.");
                abort(503, 'Stream process failed during playlist generation.');
            }

            Log::debug("HLS Playlist Logic: M3U8 not found for channel {$channel->id} ('{$originalChannelName}') on attempt {$attempt} of {$maxAttempts}. Sleeping for {$sleepSeconds}s.");
            if ($attempt === $maxAttempts) {
                $pid = $this->hlsService->getPid('channel', $channel->id) ?? 'N/A';
                Log::error("HLS Playlist Logic: M3U8 for channel {$channel->id} ('{$originalChannelName}') not found after {$maxAttempts} attempts. FFmpeg PID: {$pid}. Aborting with 503, playlist generation timed out.");
                abort(503, 'Playlist generation timed out.');
            }
            usleep((int)($sleepSeconds * 1000000));
        }
        Log::error("HLS Playlist Logic: Reached unexpected end of function for channel {$channel->id} ('{$originalChannelName}'). This should not happen.");
        abort(500, 'Internal server error in playlist serving.');
    }

    /**
     * Helper to create the HLS response.
     */
    private function createHlsResponse(string $nginxRedirectPath): \Illuminate\Http\Response
    {
        return response('', 200, [
            'Content-Type'      => 'application/vnd.apple.mpegurl',
            'X-Accel-Redirect'  => $nginxRedirectPath,
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * This is a placeholder for the old servePlaylist logic, primarily for episodes.
     */
    private function serveLegacyPlaylist(string $type, string $encodedId, Episode $model, string $title): \Illuminate\Http\Response
    {
        Log::warning("HLS Playlist (Legacy): Serving request for {$type} ID {$model->id} ('{$title}') using deprecated legacy logic. This path should be updated or removed if episodes are to use the new provider system.");

        if (!$this->hlsService->isRunning($type, $model->id)) {
            Log::error("HLS Playlist (Legacy): Stream for {$type} {$model->id} ('{$title}') not running. Current HlsStreamService::startStream is Channel-specific. Aborting.");
            abort(503, 'Episode streaming logic requires review for provider system or dedicated legacy handling.');
        }

        $pathPrefix = 'e/';
        $m3u8Path = Storage::disk('app')->path("hls/{$pathPrefix}{$model->id}/stream.m3u8");
        $nginxRedirectPath = "/internal/hls/{$pathPrefix}{$model->id}/stream.m3u8";

        if (file_exists($m3u8Path)) {
            Log::info("HLS Playlist (Legacy): Found M3U8 for {$type} {$model->id} ('{$title}'). Serving.");
            return $this->createHlsResponse($nginxRedirectPath);
        }

        Log::error("HLS Playlist (Legacy): M3U8 not found for {$type} {$model->id} ('{$title}') at path {$m3u8Path}. Aborting.");
        abort(404, 'Legacy playlist not found.');
    }


    /**
     * Serve a segment for a channel or episode.
     */
    private function serveSegment(string $type, Channel|Episode $model, string $segmentName): \Illuminate\Http\Response
    {
        $modelId = $model->id;
        $modelNameForLog = ($model instanceof Channel) ? strip_tags($model->title_custom ?? $model->title) : (($model instanceof Episode) ? strip_tags($model->title) : 'N/A');
        Log::debug("HLS Segment Logic: Request for {$type} ID {$modelId} ('{$modelNameForLog}'), segment: {$segmentName}.");

        $pathPrefix = ($type === 'channel') ? '' : 'e/';
        $nginxRedirectPath = "/internal/hls/{$pathPrefix}{$modelId}/{$segmentName}";
        $fullStoragePath = Storage::disk('app')->path("hls/{$pathPrefix}{$modelId}/{$segmentName}");

        if ($type === 'channel' && $model instanceof Channel) {
            if ($model->stream_status !== 'playing' && $model->stream_status !== 'switching') {
                Log::warning("HLS Segment Logic: Channel {$modelId} ('{$modelNameForLog}') status is '{$model->stream_status}'. Segment {$segmentName} request might be for an old or failing stream.");
            }
            if (!$this->hlsService->isRunning('channel', $modelId) && !file_exists($fullStoragePath)) {
                Log::error("HLS Segment Logic: FFmpeg process for channel {$modelId} ('{$modelNameForLog}') not running AND segment {$segmentName} not found. Aborting with 404.");
                abort(404, 'Stream not active and segment not found.');
            }
        } else if ($type === 'episode' && $model instanceof Episode) {
             if (!$this->hlsService->isRunning('episode', $modelId) && !file_exists($fullStoragePath)) {
                Log::error("HLS Segment Logic: FFmpeg process for episode {$modelId} ('{$modelNameForLog}') not running AND segment {$segmentName} not found. Aborting with 404.");
                abort(404, 'Episode stream not active and segment not found.');
            }
        }

        if (!file_exists($fullStoragePath)) {
            Log::warning("HLS Segment Logic: Segment {$segmentName} for {$type} ID {$modelId} ('{$modelNameForLog}') not found at path {$fullStoragePath}. Client may be requesting too quickly, or stream just ended/failed, or segment was cleaned up.");
            return response('Segment not found', 404, ['Content-Type' => 'video/MP2T', 'Cache-Control' => 'no-cache']);
        }

        Redis::transaction(function () use ($type, $modelId) {
            Redis::set("hls:{$type}_last_seen:{$modelId}", now()->timestamp);
            Redis::sadd("hls:active_{$type}_ids", $modelId);
        });

        Log::debug("HLS Segment Logic: Serving segment {$segmentName} for {$type} ID {$modelId} ('{$modelNameForLog}') via X-Accel-Redirect: {$nginxRedirectPath}");
        return response('', 200, [
            'Content-Type'     => 'video/MP2T',
            'X-Accel-Redirect' => $nginxRedirectPath,
            'Cache-Control'    => 'no-cache, no-transform',
            'Connection'       => 'keep-alive',
        ]);
    }
}

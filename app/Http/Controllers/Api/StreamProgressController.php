<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamProgressController extends Controller
{
    public function handleProgress(Request $request, $streamId)
    {
        $rawData = $request->getContent();
        // Log::info("Received FFmpeg progress for stream {$streamId}: " . $rawData);

        $lines = explode("\n", trim($rawData));
        $stats = [];
        $lastCompleteStats = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (str_starts_with($line, 'progress=')) {
                // This line indicates the end of a progress block (continue or end)
                // We consider the $stats collected so far as one complete block
                if (!empty($stats)) {
                    $lastCompleteStats = $stats; 
                }
                // If progress=end, we might want to do final processing or logging
                if ($line === 'progress=end') {
                    Log::info("FFmpeg progress ended for stream {$streamId}. Last stats: " . json_encode($lastCompleteStats));
                }
                $stats = []; // Reset for the next block, though typically one block per request
            } else {
                $parts = explode('=', $line, 2);
                if (count($parts) == 2) {
                    $stats[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        
        // If the loop finished and there were stats not followed by a 'progress=' line,
        // consider them the last complete block.
        if (!empty($stats)) {
            $lastCompleteStats = $stats;
        }

        if (empty($lastCompleteStats)) {
            Log::warning("No complete progress data block found for stream {$streamId}. Raw: " . $rawData);
            return response()->json(['status' => 'no_data_block_found'], 400);
        }

        $numericBitrate = null;
        $numericFps = null;

        if (isset($lastCompleteStats['bitrate'])) {
            $bitrateStr = str_ireplace('kbits/s', '', $lastCompleteStats['bitrate']);
            $numericBitrate = (float)trim($bitrateStr);
            if (!is_numeric($numericBitrate) || $numericBitrate < 0) { // Basic validation
                Log::warning("Invalid bitrate value for stream {$streamId}: {$lastCompleteStats['bitrate']}");
                $numericBitrate = null;
            }
        }

        if (isset($lastCompleteStats['fps'])) {
            $numericFps = (float)trim($lastCompleteStats['fps']);
             if (!is_numeric($numericFps) || $numericFps < 0) { // Basic validation
                Log::warning("Invalid FPS value for stream {$streamId}: {$lastCompleteStats['fps']}");
                $numericFps = null;
            }
        }
        
        // Even if one metric is missing, we still want to record the timestamp and available metrics
        if ($numericBitrate === null && $numericFps === null) {
            Log::info("Both bitrate and FPS are missing or invalid for stream {$streamId}. Stats: " . json_encode($lastCompleteStats));
            // We could return here, or proceed to at least store a timestamp if that's desired.
            // For now, let's proceed if at least one is valid, or just for timestamp.
        }

        try {
            $timestamp = now()->format('H:i:s');
            $redisBaseKey = "mpts:hist:{$streamId}";

            // Store timestamp
            Redis::lpush("{$redisBaseKey}:timestamps", $timestamp);
            Redis::ltrim("{$redisBaseKey}:timestamps", 0, 299);

            // Store bitrate if available and valid
            if ($numericBitrate !== null) {
                Redis::lpush("{$redisBaseKey}:bitrate", $numericBitrate);
                Redis::ltrim("{$redisBaseKey}:bitrate", 0, 299);
            } else {
                // If bitrate is not available, we might push a placeholder like 0 or 'N/A'
                // Or simply not push anything for bitrate for this timestamp.
                // For now, let's push 0 if it was expected but invalid/missing.
                // However, the chart might look better if we skip pushing, or push the previous value.
                // Let's push 0 for now, as per original thought of handling NaN gracefully.
                Redis::lpush("{$redisBaseKey}:bitrate", 0); // Push 0 if no valid bitrate
                Redis::ltrim("{$redisBaseKey}:bitrate", 0, 299);
                Log::debug("Pushed 0 for bitrate for stream {$streamId} due to missing/invalid data.");
            }

            // Store FPS if available and valid
            if ($numericFps !== null) {
                Redis::lpush("{$redisBaseKey}:fps", $numericFps);
                Redis::ltrim("{$redisBaseKey}:fps", 0, 299);
            } else {
                // Similar to bitrate, push 0 if no valid FPS
                Redis::lpush("{$redisBaseKey}:fps", 0);
                Redis::ltrim("{$redisBaseKey}:fps", 0, 299);
                Log::debug("Pushed 0 for FPS for stream {$streamId} due to missing/invalid data.");
            }
            
            Log::info("Successfully processed and stored FFmpeg progress for stream {$streamId}. Bitrate: {$numericBitrate}, FPS: {$numericFps}");

        } catch (\Exception $e) {
            Log::error("Error storing FFmpeg progress for stream {$streamId} in Redis: " . $e->getMessage());
            return response()->json(['status' => 'redis_error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'received_and_processed']);
    }
}

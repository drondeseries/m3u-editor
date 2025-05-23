<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon; // For date formatting, if available and preferred
// use Jenssegers\Agent\Agent; // Will check for this class existence later

class StreamStatsController extends Controller
{
    /**
     * Retrieve and display active stream statistics.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActiveStreams(Request $request)
    {
        $activeStreamIds = Redis::smembers("stream_stats:active_ids");
        $activeStreams = [];

        $agentAvailable = class_exists(\Jenssegers\Agent\Agent::class);
        $agentInstance = $agentAvailable ? new \Jenssegers\Agent\Agent() : null;

        foreach ($activeStreamIds as $streamId) {
            $streamDetails = Redis::hgetall("stream_stats:details:" . $streamId);

            if (empty($streamDetails)) {
                // Potentially a stream ID that expired or was cleaned up
                // but somehow still in the active_ids set (should be rare).
                // Or, the details hash might be empty if not populated correctly.
                continue;
            }

            // Calculate current duration
            if (isset($streamDetails['start_time_unix'])) {
                $streamDetails['current_duration_seconds'] = time() - (int)$streamDetails['start_time_unix'];
                $streamDetails['start_time_readable'] = Carbon::createFromTimestamp((int)$streamDetails['start_time_unix'])->toDateTimeString();
            } else {
                $streamDetails['current_duration_seconds'] = 'N/A';
                $streamDetails['start_time_readable'] = 'N/A';
            }
            
            // Ensure all expected keys are present, providing defaults if not
            $expectedKeys = [
                'stream_id' => 'N/A', 'channel_id' => 'N/A', 'channel_title' => 'N/A',
                'client_ip' => 'N/A', 'user_agent_raw' => 'N/A', 'stream_type' => 'N/A',
                'stream_format_requested' => 'N/A', 'video_codec_selected' => 'N/A',
                'audio_codec_selected' => 'N/A', 'hw_accel_method_used' => 'N/A',
                'ffmpeg_pid' => 'N/A', 'start_time_unix' => 'N/A',
                'source_stream_url' => 'N/A', 'ffmpeg_command' => 'N/A'
            ];
             // Merge with defaults to ensure all keys exist
            $streamDetails = array_merge($expectedKeys, $streamDetails);


            // User Agent Parsing
            $streamDetails['user_agent_display'] = $streamDetails['user_agent_raw']; // Default display
            if ($agentInstance && !empty($streamDetails['user_agent_raw']) && $streamDetails['user_agent_raw'] !== 'N/A') {
                $agentInstance->setUserAgent($streamDetails['user_agent_raw']);
                $streamDetails['parsed_device'] = $agentInstance->device() ?: 'Unknown';
                $streamDetails['parsed_platform'] = $agentInstance->platform() ?: 'Unknown';
                $streamDetails['parsed_browser'] = $agentInstance->browser() ?: 'Unknown';
                // Combine for a nicer display string if parts are known
                if ($streamDetails['parsed_browser'] !== 'Unknown' && $streamDetails['parsed_platform'] !== 'Unknown') {
                    $streamDetails['user_agent_display'] = $streamDetails['parsed_browser'] . ' on ' . $streamDetails['parsed_platform'];
                } elseif ($streamDetails['parsed_browser'] !== 'Unknown') {
                    $streamDetails['user_agent_display'] = $streamDetails['parsed_browser'];
                } elseif ($streamDetails['parsed_platform'] !== 'Unknown') {
                     $streamDetails['user_agent_display'] = 'Unknown Browser on ' . $streamDetails['parsed_platform'];
                }
                 $streamDetails['user_agent_parsed_status'] = "Parsed with Agent library";
            } else if (!empty($streamDetails['user_agent_raw']) && $streamDetails['user_agent_raw'] !== 'N/A') {
                $streamDetails['user_agent_parsed_status'] = "Agent library not available or user_agent_raw is N/A";
            } else {
                $streamDetails['user_agent_parsed_status'] = "User agent not available";
            }


            $activeStreams[] = $streamDetails;
        }

        return response()->json($activeStreams);
    }

    /**
     * Retrieve data from getActiveStreams and pass it to a Blade view.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function showBasicStatsView(Request $request)
    {
        $jsonResponse = $this->getActiveStreams($request);
        // getData(true) converts the JSON response to an associative array.
        // Since getActiveStreams returns response()->json($activeStreams),
        // $activeStreamsData will be the array of streams directly.
        $activeStreamsData = $jsonResponse->getData(true);

        return view('admin.stats.basic_stream_stats', ['streams' => $activeStreamsData]);
    }
}

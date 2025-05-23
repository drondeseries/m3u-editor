<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Stream Statistics</title>
    <meta http-equiv="refresh" content="10">
    <style>
        body { font-family: sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .container { max-width: 1600px; margin: auto; }
        .timestamp { min-width: 160px; }
        .duration { min-width: 80px; }
        .user-agent { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Active Stream Statistics</h1>

        @if(isset($streams) && count($streams) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Channel Title</th>
                        <th>Client IP</th>
                        <th class="user-agent">User Agent</th>
                        <th>Stream Type</th>
                        <th>Format</th>
                        <th>Video Codec</th>
                        <th>HW Accel</th>
                        <th class="timestamp">Start Time</th>
                        <th class="duration">Duration</th>
                        <th>PID</th>
                        <th>Source URL</th>
                        {{-- Keep ffmpeg_command commented out for now unless explicitly needed by user --}}
                        {{-- <th>FFmpeg Command</th> --}}
                    </tr>
                </thead>
                <tbody>
                    @foreach($streams as $stream)
                        <tr>
                            <td>{{ $stream['channel_title'] ?? 'N/A' }}</td>
                            <td>{{ $stream['client_ip'] ?? 'N/A' }}</td>
                            <td class="user-agent" title="{{ $stream['user_agent_raw'] ?? '' }}">{{ $stream['user_agent_display'] ?? ($stream['user_agent_raw'] ?? 'N/A') }}</td>
                            <td>{{ $stream['stream_type'] ?? 'N/A' }}</td>
                            <td>{{ $stream['stream_format_requested'] ?? 'N/A' }}</td>
                            <td>{{ $stream['video_codec_selected'] ?? 'N/A' }}</td>
                            <td>{{ $stream['hw_accel_method_used'] ?? 'N/A' }}</td>
                            <td class="timestamp">{{ $stream['start_time_readable'] ?? 'N/A' }}</td>
                            <td class="duration">{{ isset($stream['current_duration_seconds']) ? gmdate("H:i:s", $stream['current_duration_seconds']) : 'N/A' }}</td>
                            <td>{{ $stream['ffmpeg_pid'] ?? 'N/A' }}</td>
                            <td title="{{ $stream['source_stream_url'] ?? '' }}">{{ Str::limit($stream['source_stream_url'] ?? 'N/A', 50) }}</td>
                            {{-- <td title="{{ $stream['ffmpeg_command'] ?? '' }}">{{ Str::limit($stream['ffmpeg_command'] ?? 'N/A', 30) }}</td> --}}
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No active streams currently.</p>
        @endif
    </div>
</body>
</html>

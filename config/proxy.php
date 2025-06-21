<?php

return [
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'proxy_format' => env('PROXY_FORMAT', 'mpts'), // 'mpts' or 'hls'
    'ffmpeg_path' => env('PROXY_FFMPEG_PATH', null),
    'ffmpeg_additional_args' => env('PROXY_FFMPEG_ADDITIONAL_ARGS', ''),
    'ffmpeg_codec_video' => env('PROXY_FFMPEG_CODEC_VIDEO', null),
    'ffmpeg_codec_audio' => env('PROXY_FFMPEG_CODEC_AUDIO', null),
    'ffmpeg_codec_subtitles' => env('PROXY_FFMPEG_CODEC_SUBTITLES', null),

    /*
    |--------------------------------------------------------------------------
    | Live Failover Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the FFmpeg live stream failover monitoring and handling.
    |
    */
    'ffmpeg_live_failover_enabled' => env('PROXY_FFMPEG_LIVE_FAILOVER_ENABLED', false),

    // Directory where FFmpeg stderr logs for monitored streams will be stored.
    // Path is relative to storage_path().
    'ffmpeg_stderr_log_directory' => env('PROXY_FFMPEG_STDERR_LOG_DIRECTORY', 'logs/ffmpeg_stderr'),
    // How long to keep stderr log files for streams (in days).
    'ffmpeg_stderr_log_retention_days' => env('PROXY_FFMPEG_STDERR_LOG_RETENTION_DAYS', 2),

    // Patterns to detect in FFmpeg stderr that indicate a critical, persistent error.
    'ffmpeg_live_failover_error_patterns' => [
        "failed to resolve hostname",
        "Connection refused",
        "Connection timed out",
        "403 Forbidden",
        "404 Not Found",
        "500 Internal Server Error",
        "503 Service Unavailable",
        "509 Bandwidth Limit Exceeded",
        "Input/output error",
        "Conversion failed!",
        "Unable to open resource",
        "Server error: Failed to reload playlist",
        "Too many packets buffered for output stream",
        "Error number -110", // Connection timed out (from avformat)
        "Error number -104", // Connection reset by peer
        "Error number -5",   // Input/output error
        // Add more specific or common critical FFmpeg errors as needed
    ],
    // Number of times an error pattern must be detected within the threshold seconds to trigger failover.
    'ffmpeg_live_failover_error_threshold_count' => env('PROXY_FFMPEG_LIVE_FAILOVER_ERROR_THRESHOLD_COUNT', 3),
    // Time window (in seconds) for the error threshold count.
    'ffmpeg_live_failover_error_threshold_seconds' => env('PROXY_FFMPEG_LIVE_FAILOVER_ERROR_THRESHOLD_SECONDS', 30),

    // How often (in seconds) the MonitorFfmpegStreamJob should check the stderr log and stream status.
    'ffmpeg_monitor_interval_seconds' => env('PROXY_FFMPEG_MONITOR_INTERVAL_SECONDS', 5),

    /**
     * The dedicated queue name for the long-running FFmpeg monitoring job.
     * This allows us to use a separate, long-timeout worker for these jobs.
     */
    'ffmpeg_monitor_job_queue' => env('PROXY_FFMPEG_MONITOR_JOB_QUEUE', 'monitoring'),

    // Unique lock timeout for the monitoring job (in seconds).
    'ffmpeg_monitor_job_unique_lock_timeout' => env('PROXY_FFMPEG_MONITOR_JOB_UNIQUE_LOCK_TIMEOUT', 86400), // 24 hours

    // Cooldown period (in seconds) before a URL that failed live monitoring can be retried by failover.
    'ffmpeg_live_failover_bad_source_cooldown_seconds' => env('PROXY_FFMPEG_LIVE_FAILOVER_BAD_SOURCE_COOLDOWN_SECONDS', 300), // 5 minutes
    // Cooldown period (in seconds) if all available sources for a stream fail during a live failover attempt.
    // The stream itself will be blocked from new live failover attempts for this duration.
    'ffmpeg_live_failover_all_sources_failed_cooldown_seconds' => env('PROXY_FFMPEG_LIVE_FAILOVER_ALL_SOURCES_FAILED_COOLDOWN_SECONDS', 900), // 15 minutes

];
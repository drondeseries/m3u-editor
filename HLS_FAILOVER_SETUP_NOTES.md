# HLS Stream Failover System - Configuration & Setup Notes

This document outlines the necessary configurations and setup steps to run the HLS Stream Failover system.

## 1. Server Requirements

*   **PHP:** Ensure your PHP version meets Laravel's requirements.
*   **FFmpeg & ffprobe:** These binaries must be installed on the server and accessible in the system's PATH. FFmpeg is used for processing HLS streams, and ffprobe is used for stream validation.
    *   Installation example (Ubuntu): `sudo apt update && sudo apt install ffmpeg`
*   **Web Server:** Nginx or Apache. Configuration examples for serving HLS segments are provided below.
*   **Database:** A configured database supported by Laravel (MySQL, PostgreSQL, etc.).
*   **Supervisor (Recommended for Queue Workers):** For keeping Laravel queue workers running.

## 2. Laravel Application Setup

### a. Directory Permissions
The following directories (and their subdirectories) need to be writable by the web server user and the queue worker user:
*   `storage/app/hls/` (for HLS playlists and segments)
*   `storage/framework/`
*   `storage/logs/` (including `storage/logs/ffmpeg/`)
*   `bootstrap/cache/`

Example (run from project root):
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
# Adjust user/group (www-data) as per your server setup.
```

### b. Environment Variables (`.env`)
Ensure the following are configured in your `.env` file:
```dotenv
APP_NAME="Your Application Name"
APP_ENV=production # or local
APP_KEY= # Should be generated with php artisan key:generate
APP_DEBUG=false # In production
APP_URL=http://yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=info # or debug for more verbose logging initially

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Queue Driver (database or redis recommended for production)
QUEUE_CONNECTION=database # or redis, sync (for local testing)

# If using Redis for Queues or Cache
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Broadcasting (using Pusher as an example, replace with your chosen driver e.g., Soketi)
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1

# If using a self-hosted solution like Soketi or Laravel WebSockets:
# LARAVEL_WEBSOCKETS_PORT=6001
# LARAVEL_WEBSOCKETS_HOST=yourdomain.com
# LARAVEL_WEBSOCKETS_SSL_KEY=/path/to/ssl.key (if using SSL)
# LARAVEL_WEBSOCKETS_SSL_CERT=/path/to/ssl.crt (if using SSL)
# VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}" # For frontend if using Vite
# VITE_PUSHER_HOST="${LARAVEL_WEBSOCKETS_HOST}"
# VITE_PUSHER_PORT="${LARAVEL_WEBSOCKETS_PORT}"
# VITE_PUSHER_SCHEME="${LARAVEL_WEBSOCKETS_SSL_KEY}" ? "https" : "http"
# VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

### c. Database Migrations
Run the database migrations:
```bash
php artisan migrate
```

**Important Note on Idempotency and `channels` Table:**
The migration files, including those for `channels`, `channel_streams`, and `user_stream_sessions`, have been updated to be idempotent. This means they check if a table already exists before attempting to create it (and use `dropIfExists` for rollbacks). This helps prevent "Duplicate table" errors if migrations are run multiple times.

**CRITICAL: Schema Conflict for `channels` Table**
Two migration files exist that both attempt to create a `channels` table, but with different schemas:
1.  `database/migrations/2023_10_26_000000_create_channels_table.php` (related to the HLS failover feature, includes `active_channel_stream_id`)
2.  `database/migrations/2024_12_19_154249_create_channels_table.php` (appears to be for general channel/playlist management with a more extensive schema).

Both have been made idempotent to prevent immediate migration errors. However, this means:
*   If the `channels` table does not exist, the schema created will depend on which of these two migrations runs first.
*   If the `channels` table *does* exist, neither migration will now alter its schema.

**Action Required by Developer:**
You MUST review these two migration files and decide on the correct, canonical schema for the `channels` table.
*   If the `2023_10_26_...` schema is correct for the HLS failover and is the intended base, the `2024_12_19_...` migration might need to be removed, or its unique columns merged into the 2023 migration if they are also needed.
*   If the `2024_12_19_...` schema is the correct one, then the `active_channel_stream_id` column (and its foreign key definition from `2023_10_26_000003_add_active_channel_stream_foreign_key_to_channels_table.php`) MUST be added to the `2024_12_19_...` migration's schema, and the `2023_10_26_000000_create_channels_table.php` should likely be removed or its content commented out.
*   The HLS failover system relies on the `active_channel_stream_id` column being present in the `channels` table and correctly linked via foreign key to the `channel_streams` table.
Please resolve this schema conflict before deploying to production to ensure data integrity and correct feature operation.

**Note on Failover-Related Tables:**
The system uses two main concepts for handling stream redundancy:
*   **`channel_streams` Table:** This table, central to the automated HLS failover implemented by the jobs (`StartStreamProcessingJob`, `MonitorStreamHealthJob`), stores multiple HLS source URLs for a *single logical channel*. The system automatically switches between these URLs if one fails or stalls.
*   **`channel_failovers` Table:** This table (managed via Filament, as seen in `ChannelResource.php`) allows users to define relationships where one entire *channel* can act as a failover for another *channel*. For example, if "Channel A" is completely unavailable, a user might be manually or (via future enhancements) automatically redirected to "Channel B". The current automated HLS failover jobs do not yet utilize this table for automatic redirection to a different channel, but it's available for administrative setup and potential future integration.

### d. Queue Workers
Queue workers are essential for processing stream startups, monitoring, and recovery tasks.
*   Ensure your `QUEUE_CONNECTION` in `.env` is set to `database`, `redis`, or another async driver for production.
*   Start queue workers. It's recommended to use Supervisor to keep them running:
    ```bash
    # Example: Process default, streaming, and monitoring queues
    php artisan queue:work --queue=default,streaming,monitoring --tries=3 --timeout=300
    ```
*   **Supervisor Configuration Example (`/etc/supervisor/conf.d/laravel-worker.conf`):**
    ```ini
    [program:laravel-worker]
    process_name=%(program_name)s_%(process_num)02d
    command=php /path/to/your-project/artisan queue:work --queue=default,streaming,monitoring --sleep=3 --tries=3 --max-time=3600
    autostart=true
    autorestart=true
    user=your_server_user ; or www-data
    numprocs=2 ; Adjust based on server resources and needs
    redirect_stderr=true
    stdout_logfile=/path/to/your-project/storage/logs/worker.log
    stopwaitsecs=3600
    ```
    Remember to update paths and user. After creating/editing, reload Supervisor:
    `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start laravel-worker:*`

### e. Task Scheduling (Laravel 11+ via `bootstrap/app.php`)
The `ProcessProblematicChannelStreamsJob` is dispatched by the `streams:process-problematic` Artisan command. This Artisan command should be scheduled to run periodically (e.g., every two minutes) via Laravel's built-in scheduler.

**1. Define the Schedule:**
In your `bootstrap/app.php` file, add or modify the `withSchedule` call:

```php
<?php
// bootstrap/app.php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
// Ensure your command is imported if using class name scheduling
// use App\Console\Commands\ProcessProblematicStreams;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php', // Or where your commands are registered
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // $middleware->web(append: [ ... ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ...
    })
    ->withSchedule(function (Schedule $schedule) {
        // Using the command signature:
        $schedule->command('streams:process-problematic')->everyTwoMinutes();

        // Or, if you prefer using the class name (ensure import):
        // $schedule->command(ProcessProblematicStreams::class)->everyTwoMinutes();
    })->create();
```
Make sure to adjust the `use` statement and the `commands:` path in `withRouting` if your Artisan command is in a different location or namespace.

**2. System Cron Job for `schedule:run`:**
A single system cron job is needed to trigger Laravel's scheduler every minute.
Edit your crontab: `crontab -e`
Add the following line:
```cron
* * * * * cd /path/to/your-project && php artisan schedule:run >> /dev/null 2>&1
```
This setup ensures that Laravel's scheduler checks and runs any due tasks (like our `streams:process-problematic` command) every minute. The command itself is configured within Laravel to run every two minutes.

### f. Laravel Echo & Broadcasting
1.  **Install Echo & Pusher JS (or alternative):**
    ```bash
    npm install --save-dev laravel-echo pusher-js
    # or if using Soketi/Laravel-Websockets:
    # npm install --save-dev laravel-echo socket.io-client
    ```
2.  **Configure `resources/js/bootstrap.js`:**
    Uncomment the Laravel Echo section and configure it with your chosen driver and settings (matching your `.env` variables).
    ```javascript
    // Example for Pusher:
    // import Echo from 'laravel-echo';
    // import Pusher from 'pusher-js';
    // window.Pusher = Pusher;
    // window.Echo = new Echo({
    //     broadcaster: 'pusher',
    //     key: process.env.MIX_PUSHER_APP_KEY,
    //     cluster: process.env.MIX_PUSHER_APP_CLUSTER,
    //     forceTLS: (process.env.MIX_PUSHER_SCHEME ?? 'https') === 'https',
    //     wsHost: process.env.MIX_PUSHER_HOST ?? `ws-\${process.env.MIX_PUSHER_APP_CLUSTER}.pusher.com`,
    //     wsPort: process.env.MIX_PUSHER_PORT ?? 80,
    //     wssPort: process.env.MIX_PUSHER_PORT ?? 443,
    //     enabledTransports: ['ws', 'wss'],
    // });

    // Example for Soketi/Laravel-Websockets (ensure .env vars like VITE_PUSHER_HOST, VITE_PUSHER_PORT are set for Vite)
    // window.Echo = new Echo({
    //     broadcaster: 'pusher', // Yes, still 'pusher' for Laravel WebSockets/Soketi
    //     key: import.meta.env.VITE_PUSHER_APP_KEY,
    //     wsHost: import.meta.env.VITE_PUSHER_HOST,
    //     wsPort: import.meta.env.VITE_PUSHER_PORT,
    //     wssPort: import.meta.env.VITE_PUSHER_PORT, // if using SSL for websockets
    //     forceTLS: import.meta.env.VITE_PUSHER_SCHEME === 'https',
    //     disableStats: true,
    //     enabledTransports: ['ws', 'wss'],
    // });
    ```
3.  **Compile Assets:** `npm run dev` or `npm run build`.
4.  **Broadcasting Service Provider:** Ensure `App\Providers\BroadcastServiceProvider` is uncommented in `config/app.php`.
5.  **Channel Authorization (`routes/channels.php`):**
    Define authorization logic for the private channels used by events.
    ```php
    Broadcast::channel('stream-session.{sessionId}', function ($user, $sessionId) {
        // For authenticated users:
        // return (int) $user->id === (int) $expectedUserIdBasedOnSessionId;
        // For guest sessions, if session_id is directly from Laravel session:
        // return $request->session()->getId() === $sessionId;
        // For UUID-based session_id stored in cookie and passed by client:
        // This requires the client to send its session_id for verification,
        // or a more sophisticated auth mechanism for private channels with guests.
        // For simplicity, if the session ID is considered trustworthy enough for this context:
        return true; // WARNING: In a real app, ensure proper auth here!
    });
    ```
6.  **Echo Server:** Set up and run your WebSocket server (Soketi, Laravel WebSockets, or configure Pusher).

## 3. Web Server Configuration for HLS

For optimal performance, HLS segments (.m3u8, .ts) should be served directly by Nginx or Apache, not through PHP/Laravel. The HLS files are generated in `storage/app/hls/{sessionId}_{channelStreamId}/`.

You'll need to create a symbolic link from `public/storage` to `storage/app/public` if it doesn't exist:
`php artisan storage:link`
Then, adapt HLS paths in jobs/controllers to use `storage/app/public/hls/...` or configure Nginx/Apache to serve directly from `storage/app/hls/` if security permits and paths are handled carefully.

A common approach is to have FFmpeg write to `storage/app/public/hls/...` and then serve from `yourdomain.com/storage/hls/...`.

### a. Nginx Example
```nginx
server {
    # ... your other server config ...

    location ~ ^/storage/hls/.*\.(m3u8|ts)$ {
        alias /path/to/your-project/storage/app/public/hls/$1/$2; # Or directly storage/app/hls if symlink isn't used for this path

        # For .m3u8 files
        if ($request_uri ~* \.m3u8$) {
            add_header Content-Type 'application/vnd.apple.mpegurl';
        }
        # For .ts files
        if ($request_uri ~* \.ts$) {
            add_header Content-Type 'video/mp2t';
        }

        add_header Cache-Control 'no-cache, no-store, must-revalidate';
        add_header Access-Control-Allow-Origin '*'; # Adjust for security
        expires -1;
    }

    # Ensure PHP requests for other stream control paths are handled by Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ... other php-fpm config ...
}
```
**Note:** The Nginx alias/root needs to correctly map the URL path to the filesystem path where FFmpeg saves segments. The example above is conceptual for the path. The actual path FFmpeg writes to is `storage/app/hls/{sessionId}_{channelStreamId}/`. You'd need an Nginx location block that can map a public URL like `/hls-segments/{sessionId}_{channelStreamId}/master.m3u8` to the correct storage path. This often involves a more complex regex or a dedicated serving route in Laravel if direct mapping is hard.

A simpler Nginx approach if FFmpeg writes to `storage/app/public/hls/`:
```nginx
location /storage/hls {
    alias /path/to/your-project/storage/app/public/hls; # Or where your symlink points

    location ~ \.m3u8$ {
        add_header Content-Type 'application/vnd.apple.mpegurl';
        add_header Cache-Control 'no-cache, no-store';
    }
    location ~ \.ts$ {
        add_header Content-Type 'video/mp2t';
        # Consider adding cache headers for TS segments if appropriate
    }
    add_header Access-Control-Allow-Origin '*';
}
```

### b. Apache Example (`.htaccess` or vhost config)
Ensure `mod_alias` and `mod_headers` are enabled.
```apache
# In your public/.htaccess or VirtualHost configuration

# If using storage symlink (public/storage -> storage/app/public)
Alias /storage/hls /path/to/your-project/storage/app/public/hls

<Directory /path/to/your-project/storage/app/public/hls>
    Options Indexes FollowSymLinks
    AllowOverride None
    Require all granted

    <FilesMatch "\.m3u8$">
        Header set Content-Type "application/vnd.apple.mpegurl"
        Header set Cache-Control "no-cache, no-store, must-revalidate"
    </FilesMatch>
    <FilesMatch "\.ts$">
        Header set Content-Type "video/mp2t"
        # Header set Cache-Control "public, max-age=..." # Optional caching for segments
    </FilesMatch>

    Header set Access-Control-Allow-Origin "*"
</Directory>
```
Adjust paths accordingly.

## 5. Brief Operational Overview

1.  **Client Request:** A user requests a channel stream via an API endpoint (e.g., `/stream/channel/{channel_id}/request`).
2.  **Stream Selection (`StreamController`):**
    *   The controller identifies the user/session.
    *   It checks if the user already has an active, healthy stream session for this channel.
    *   If not, it iterates through available `ChannelStream` records for the requested channel, ordered by priority.
    *   Each potential stream is quickly validated using `ffprobe`.
    *   The first healthy stream is selected. If none are found, an error is returned.
3.  **Session Tracking (`UserStreamSession`):**
    *   A `UserStreamSession` record is created or updated to track the selected `ChannelStream`, user/session details, and eventually the FFmpeg process ID.
4.  **FFmpeg Processing (`StartStreamProcessingJob`):**
    *   This job is dispatched for the user's session and selected stream.
    *   It ensures no other healthy FFmpeg process is running for this specific session stream.
    *   It starts a new FFmpeg process, which transcodes/repackages the source HLS into a new HLS stream stored locally (e.g., in `storage/app/hls/{sessionId}_{channelStreamId}/`).
    *   The FFmpeg PID is stored in `UserStreamSession`.
    *   It then dispatches `MonitorStreamHealthJob`.
5.  **Client Playback:** The client receives an M3U8 URL (e.g., `https://your-app.com/stream/hls/{sessionId}/{channelStreamId}/master.m3u8`) and starts playback. The web server (Nginx/Apache) serves these HLS files.
6.  **Stream Monitoring (`MonitorStreamHealthJob`):**
    *   This job runs periodically for each active user stream session.
    *   It checks if the FFmpeg process is still alive.
    *   It checks the locally generated M3U8 for new segments (media sequence advancing).
    *   If FFmpeg dies or the stream stalls (no new segments for a configurable period):
        *   The problematic `ChannelStream` is marked ('problematic', failure/stall counts incremented).
        *   The current FFmpeg process is killed.
        *   The system attempts to find the next available `ChannelStream` for the *same user session*.
        *   If a new stream is found, `StartStreamProcessingJob` is dispatched for it, and a `StreamSwitchEvent` is broadcast to the client.
        *   If no alternative is found, a `StreamUnavailableEvent` is broadcast.
7.  **Client Reaction (Events):**
    *   The client, via Laravel Echo, listens for `StreamSwitchEvent` and reloads the player with the (potentially new, or same if backend handles transparently) M3U8 URL.
    *   It listens for `StreamUnavailableEvent` and informs the user.
8.  **Problematic Stream Recovery (`ProcessProblematicChannelStreamsJob`):**
    *   This scheduled Artisan command (`streams:process-problematic`) runs periodically.
    *   It checks `ChannelStream` records marked 'problematic' that haven't been validated recently.
    *   If `ffprobe` indicates a stream is healthy again, its status is updated to 'recovered', making it available for selection again.

## 6. Client-Side Implementation
Refer to "Client-Side Logic (Conceptual)" step in the plan for details on using Laravel Echo and HLS.js. Ensure your JavaScript correctly subscribes to the private channel `stream-session.{sessionId}`.

## 7. Debugging Tips

*   **Laravel Logs (`storage/logs/laravel.log`):** Check for errors related to controllers, jobs, database queries, event broadcasting, and general application flow. Increase `LOG_LEVEL` in `.env` to `debug` for more verbose output if needed.
*   **FFmpeg Logs (`storage/logs/ffmpeg/`):** Each FFmpeg process started by `StartStreamProcessingJob` should ideally log its output/errors to a unique file in this directory (e.g., `stream_{sessionId}_{channelStreamId}.log`). These logs are invaluable for diagnosing issues with FFmpeg itself (e.g., connection errors to source, transcode errors, segmenting problems). The current implementation of `StartStreamProcessingJob` sets this up.
*   **Queue Worker Logs:** If using Supervisor or similar for queue workers, their output logs (e.g., `/path/to/your-project/storage/logs/worker.log` as per Supervisor example) will show if jobs are being processed, failing, or retrying. Check for exceptions thrown by jobs.
*   **Browser Developer Console:**
    *   Check for JavaScript errors related to Echo, HLS player, or event handling.
    *   Monitor network requests to see if M3U8 playlists and TS segments are loading correctly.
    *   Look for WebSocket connection messages if Echo is configured.
*   **Web Server Logs (Nginx/Apache):** Check access and error logs of your web server for issues related to serving HLS files or PHP processing.
*   **`ffprobe` Manually:** If a stream is suspected to be problematic, run `ffprobe` manually from the server command line against the stream URL to see its direct output:
    ```bash
    ffprobe -v quiet -print_format json -show_streams -show_format "STREAM_URL_HERE"
    ```
*   **Database Inspection:** Check the `channels`, `channel_streams`, and `user_stream_sessions` tables for current statuses, PIDs, timestamps, and failure/stall counts to understand the system's state.
*   **Echo Server Logs:** If running Soketi or Laravel WebSockets, check their logs for connection issues or broadcasting errors.
```

<?php

use Illuminate\Support\Facades\Schedule;
use App\Settings\GeneralSettings; // Added for live_failover_enabled check

/*
 * Register schedules
 */

// Check for updates
Schedule::command('app:update-check')
    ->daily();

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyFiveMinutes();

// Prune stale processes
Schedule::command('app:hls-prune')
    ->everyFifteenSeconds();

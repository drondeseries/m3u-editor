<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.ffmpeg_graceful_shutdown_timeout_seconds', 10);
    }

    /**
     * Reverse the migrations.
     *
     * For this setting, if rolling back, we might remove it or set it to a
     * previous default if one existed. Given it's a new setting,
     * simply deleting it or leaving the down() method empty is common.
     */
    // public function down(): void
    // {
    //     $this->migrator->delete('general.ffmpeg_graceful_shutdown_timeout_seconds');
    // }
};

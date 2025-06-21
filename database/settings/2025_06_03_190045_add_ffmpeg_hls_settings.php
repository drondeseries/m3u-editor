<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            // Existing settings from this file
            $blueprint->add('ffmpeg_hls_time', 4);
            $blueprint->add('ffmpeg_ffprobe_timeout', 5);
            $blueprint->add('hls_playlist_max_attempts', 10);
            $blueprint->add('hls_playlist_sleep_seconds', 1.0);

            // New settings
            $blueprint->add('ffmpeg_input_copyts', true);
            $blueprint->add('ffmpeg_input_analyzeduration', '3M');
            $blueprint->add('ffmpeg_input_probesize', '3M');
            $blueprint->add('ffmpeg_input_max_delay', '5000000');
            $blueprint->add('ffmpeg_input_fflags', 'nobuffer+igndts');
            $blueprint->add('ffmpeg_output_include_aud', true);
            $blueprint->add('ffmpeg_enable_print_graphs', false);
            $blueprint->add('ffmpeg_input_stream_loop', false);
            $blueprint->add('ffmpeg_disable_subtitles', true);
            $blueprint->add('ffmpeg_audio_disposition_default', true);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            // Existing settings from this file
            $blueprint->delete('ffmpeg_hls_time');
            $blueprint->delete('ffmpeg_ffprobe_timeout');
            $blueprint->delete('hls_playlist_max_attempts');
            $blueprint->delete('hls_playlist_sleep_seconds');

            // New settings
            $blueprint->delete('ffmpeg_input_copyts');
            $blueprint->delete('ffmpeg_input_analyzeduration');
            $blueprint->delete('ffmpeg_input_probesize');
            $blueprint->delete('ffmpeg_input_max_delay');
            $blueprint->delete('ffmpeg_input_fflags');
            $blueprint->delete('ffmpeg_output_include_aud');
            $blueprint->delete('ffmpeg_enable_print_graphs');
            $blueprint->delete('ffmpeg_input_stream_loop');
            $blueprint->delete('ffmpeg_disable_subtitles');
            $blueprint->delete('ffmpeg_audio_disposition_default');
        });
    }
};

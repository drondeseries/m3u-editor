<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;
use Spatie\LaravelSettings\Migrations\SettingsBlueprint;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Check if the properties exist before adding them to avoid errors if the migration is run multiple times
        // or if the properties were somehow added manually or by a different process.
        // However, the default behavior of `add` might handle this, but explicit checks are safer.
        // For now, we'll assume `add` is idempotent or the context implies it's a new addition.

        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            // Add hardware_acceleration_method if it doesn't exist
            // Default to 'none' as in the GeneralSettings class
            $blueprint->add('hardware_acceleration_method', 'none');

            // Add ffmpeg_qsv_device if it doesn't exist
            // Default to null as in the GeneralSettings class
            $blueprint->add('ffmpeg_qsv_device', null);

            // Add ffmpeg_qsv_video_filter if it doesn't exist
            // Default to null as in the GeneralSettings class
            $blueprint->add('ffmpeg_qsv_video_filter', null);

            // Add ffmpeg_qsv_encoder_options if it doesn't exist
            // Default to null as in the GeneralSettings class
            $blueprint->add('ffmpeg_qsv_encoder_options', null);
        });
    }

    // Optional: Define a down() method if you want to make the migration reversible.
    // For this case, if the properties were truly missing, removing them might not be desired
    // if other parts of the application now rely on them.
    // However, for completeness, a down() method could be:
    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('hardware_acceleration_method');
            $blueprint->delete('ffmpeg_qsv_device');
            $blueprint->delete('ffmpeg_qsv_video_filter');
            $blueprint->delete('ffmpeg_qsv_encoder_options');
        });
    }
};

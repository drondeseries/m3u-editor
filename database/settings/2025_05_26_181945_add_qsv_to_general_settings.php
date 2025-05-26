<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;
use Spatie\LaravelSettings\Migrations\SettingsBlueprint;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('ffmpeg_qsv_enabled', false);
            $blueprint->add('ffmpeg_qsv_additional_args', null); 
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('ffmpeg_qsv_enabled');
            $blueprint->delete('ffmpeg_qsv_additional_args');
        });
    }
};

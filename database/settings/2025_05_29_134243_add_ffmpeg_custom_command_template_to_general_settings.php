<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (!$this->migrator->exists('general.ffmpeg_custom_command_template')) {
            $this->migrator->add('general.ffmpeg_custom_command_template', null);
        }
    }
};

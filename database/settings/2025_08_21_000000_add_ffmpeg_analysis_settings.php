<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.ffmpeg_analyzeduration', '1M');
        $this->migrator->add('general.ffmpeg_probesize', '1M');
    }
};

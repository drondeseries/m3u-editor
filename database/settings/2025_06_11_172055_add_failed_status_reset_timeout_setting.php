<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.failed_status_reset_timeout_minutes', 5);
    }

    /**
     * Reverse the migrations.
     *
     * In a real application, you might want to consider how to handle the down() method.
     * For this specific setting, removing it might be acceptable if the application
     * has a default fallback. Or you might choose to set it to a default value
     * or do nothing, depending on your application's needs.
     * For now, we'll leave it empty as per typical Spatie settings migrations
     * unless a specific rollback value is required.
     */
    // public function down(): void
    // {
    //     $this->migrator->delete('general.failed_status_reset_timeout_minutes');
    // }
};

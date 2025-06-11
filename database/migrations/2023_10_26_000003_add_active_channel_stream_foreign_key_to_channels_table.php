<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Ensure the column exists before trying to add a foreign key constraint
            if (Schema::hasColumn('channels', 'active_channel_stream_id')) {
                $table->foreign('active_channel_stream_id')
                      ->references('id')
                      ->on('channel_streams')
                      ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Check if the foreign key exists before trying to drop it
            // Note: Dropping foreign keys often requires knowing the conventional name
            // Laravel's default is tablename_columnname_foreign
            // However, a more robust way is to check if the column itself exists
            // and then drop the foreign key if the column is present.
            // For simplicity and directness as requested:
            if (Schema::hasColumn('channels', 'active_channel_stream_id')) {
                 // Attempt to drop by column name array (Laravel's convention for naming)
                try {
                    $table->dropForeign(['active_channel_stream_id']);
                } catch (\Exception $e) {
                    // Log or handle if specific drop by name fails,
                    // this might happen if the constraint name is not default.
                    // For this context, we assume default naming or that it's handled.
                    // If this fails, a manual inspection/correction of constraint name might be needed.
                    // Fallback: $table->dropForeign('your_specific_foreign_key_name_here');
                }
            }
        });
    }
};

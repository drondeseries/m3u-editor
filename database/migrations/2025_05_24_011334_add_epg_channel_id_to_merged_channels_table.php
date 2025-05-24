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
        Schema::table('merged_channels', function (Blueprint $table) {
            $table->foreignId('epg_channel_id')->nullable()->constrained('epg_channels')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_channels', function (Blueprint $table) {
            $table->dropForeign(['epg_channel_id']);
            $table->dropColumn('epg_channel_id');
        });
    }
};

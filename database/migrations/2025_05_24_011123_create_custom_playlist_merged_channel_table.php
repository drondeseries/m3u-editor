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
        Schema::create('custom_playlist_merged_channel', function (Blueprint $table) {
            $table->foreignId('custom_playlist_id')->constrained()->onDelete('cascade');
            $table->foreignId('merged_channel_id')->constrained()->onDelete('cascade');
            $table->primary(['custom_playlist_id', 'merged_channel_id']);
            // No timestamps needed for a simple pivot table unless specified
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_playlist_merged_channel');
    }
};

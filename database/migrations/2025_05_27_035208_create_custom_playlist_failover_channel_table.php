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
        Schema::create('custom_playlist_failover_channel', function (Blueprint $table) {
            $table->foreignId('custom_playlist_id')->constrained()->onDelete('cascade');
            $table->foreignId('failover_channel_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->primary(['custom_playlist_id', 'failover_channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_playlist_failover_channel');
    }
};

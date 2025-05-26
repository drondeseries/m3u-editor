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
        Schema::create('failover_channel_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('failover_channel_id')->constrained()->onDelete('cascade');
            $table->foreignId('channel_id')->constrained()->onDelete('cascade');
            $table->integer('order');
            $table->timestamps();

            // Indexes
            $table->unique(['failover_channel_id', 'channel_id']);
            $table->index(['failover_channel_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failover_channel_sources');
    }
};

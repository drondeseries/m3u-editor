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
        Schema::create('merged_channel_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_channel_id')->constrained()->onDelete('cascade');
            $table->foreignId('source_channel_id')->constrained('channels')->onDelete('cascade'); // Explicitly name the referenced table 'channels'
            $table->integer('priority')->default(0); // Lower numbers mean higher priority
            $table->timestamps();

            // Optional: Add a unique constraint to prevent adding the same source channel multiple times to the same merged channel
            // $table->unique(['merged_channel_id', 'source_channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merged_channel_sources');
    }
};

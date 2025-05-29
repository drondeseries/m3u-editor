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
        Schema::create('playlist_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('playlist_id')->constrained('playlists')->onDelete('cascade');
            $table->string('name');
            $table->integer('max_streams')->nullable()->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_profiles');
    }
};

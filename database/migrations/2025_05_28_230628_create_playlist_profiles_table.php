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
            $table->uuid('id')->primary(); // Using UUID as preferred
            $table->foreignUuid('playlist_id')->constrained('playlists')->cascadeOnDelete();
            $table->string('name');
            $table->integer('max_streams')->nullable()->default(null);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Note: Enforcing "only one is_default=true per playlist_id"
            // usually requires a partial unique index or application-level logic.
            // Example for PostgreSQL:
            // $table->unique(['playlist_id', 'is_default'], 'playlist_profiles_unique_default_one_true')
            //       ->where('is_default', true);
            // For other databases, this might need to be handled in the application.
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

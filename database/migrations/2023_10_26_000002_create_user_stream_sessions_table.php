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
        if (!Schema::hasTable('user_stream_sessions')) {
            Schema::create('user_stream_sessions', function (Blueprint $table) {
                $table->id(); // bigIncrements is the default for id()
                $table->string('session_id')->unique()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                // Assuming you might have a users table, otherwise this can be removed or adapted.
                // If you have a users table, you might want to add:
                // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreignId('channel_id')->constrained('channels')->onDelete('cascade');
                $table->foreignId('active_channel_stream_id')->constrained('channel_streams')->onDelete('cascade');
                $table->integer('ffmpeg_pid')->nullable();
                $table->string('worker_pid')->nullable();
                $table->string('last_segment_filename')->nullable();
                $table->integer('last_segment_media_sequence')->nullable();
                $table->timestamp('last_segment_at')->nullable();
                $table->timestamp('session_started_at')->useCurrent();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_stream_sessions');
    }
};

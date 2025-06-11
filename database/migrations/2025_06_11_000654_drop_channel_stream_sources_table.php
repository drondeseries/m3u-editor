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
        Schema::dropIfExists('channel_stream_sources');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('channel_stream_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->onDelete('cascade');
            $table->text('stream_url');
            $table->string('provider_name')->nullable();
            $table->integer('priority')->default(0)->index();
            $table->string('status', 50)->default('active')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->json('custom_headers')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};

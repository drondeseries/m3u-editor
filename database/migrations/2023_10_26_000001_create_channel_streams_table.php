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
        if (!Schema::hasTable('channel_streams')) {
            Schema::create('channel_streams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_id')->constrained('channels')->onDelete('cascade');
                $table->string('provider_name')->nullable();
                $table->text('stream_url');
                $table->integer('priority')->default(0);
                $table->string('status')->default('active')->index();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->integer('consecutive_stall_count')->default(0);
                $table->integer('consecutive_failure_count')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_streams');
    }
};

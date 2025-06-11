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
        Schema::create('channel_stream_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->onDelete('cascade');
            $table->string('stream_url');
            $table->integer('priority')->default(0);
            $table->string('provider_name')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('status')->nullable()->comment('e.g., online, offline, buffering');
            $table->timestamps();
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('current_stream_provider_id')->nullable()->constrained('channel_stream_providers')->onDelete('set null');
            $table->string('stream_status')->nullable()->comment('e.g., playing, failed, switching');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['current_stream_provider_id']);
            $table->dropColumn('current_stream_provider_id');
            $table->dropColumn('stream_status');
        });

        Schema::dropIfExists('channel_stream_providers');
    }
};

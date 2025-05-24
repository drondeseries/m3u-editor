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
        Schema::table('merged_channels', function (Blueprint $table) {
            $table->string('tvg_id')->nullable();
            $table->string('tvg_name')->nullable();
            $table->string('tvg_logo')->nullable();
            $table->string('tvg_chno')->nullable();
            $table->string('tvc_guide_stationid')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_channels', function (Blueprint $table) {
            $table->dropColumn(['tvg_id', 'tvg_name', 'tvg_logo', 'tvg_chno', 'tvc_guide_stationid']);
        });
    }
};

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
        Schema::table('failover_channel_sources', function (Blueprint $table) {
            $table->dropColumn([
                'override_tvg_id',
                'override_tvg_logo',
                'override_tvg_name',
                'override_tvg_chno',
                'override_tvg_guide_stationid',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('failover_channel_sources', function (Blueprint $table) {
            $table->string('override_tvg_id')->nullable()->after('order');
            $table->string('override_tvg_logo')->nullable()->after('override_tvg_id');
            $table->string('override_tvg_name')->nullable()->after('override_tvg_logo');
            $table->string('override_tvg_chno')->nullable()->after('override_tvg_name');
            $table->string('override_tvg_guide_stationid')->nullable()->after('override_tvg_chno');
        });
    }
};

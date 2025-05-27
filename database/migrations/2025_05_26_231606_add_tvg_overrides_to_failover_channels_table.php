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
        Schema::table('failover_channels', function (Blueprint $table) {
            $table->string('tvg_id_override')->nullable()->after('speed_threshold');
            $table->string('tvg_logo_override')->nullable()->after('tvg_id_override');
            $table->string('tvg_name_override')->nullable()->after('tvg_logo_override');
            $table->string('tvg_chno_override')->nullable()->after('tvg_name_override');
            $table->string('tvg_guide_stationid_override')->nullable()->after('tvg_chno_override');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('failover_channels', function (Blueprint $table) {
            $table->dropColumn([
                'tvg_id_override',
                'tvg_logo_override',
                'tvg_name_override',
                'tvg_chno_override',
                'tvg_guide_stationid_override',
            ]);
        });
    }
};

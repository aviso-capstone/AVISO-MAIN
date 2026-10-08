<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PSGC 10-digit barangay code resolved from the record's coordinates by
     * BarangayLocatorService (null = outside Zamboanga City). Matches
     * barangays.code from the yajra/laravel-address PSGC tables.
     */
    public function up(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->string('barangay_code', 10)->nullable()->after('area')->index();
        });

        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->string('barangay_code', 10)->nullable()->after('longitude')->index();
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropIndex(['barangay_code']);
            $table->dropColumn('barangay_code');
        });

        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropIndex(['barangay_code']);
            $table->dropColumn('barangay_code');
        });
    }
};

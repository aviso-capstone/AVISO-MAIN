<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the SOS started: the rider held the SOS button (manual), the IoT unit
     * detected a crash (crash_iot) or the phone's backup check did (crash_phone).
     * Null on alerts raised before this column existed.
     */
    public function up(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->string('trigger_type', 20)->nullable()->after('source')->comment('manual | crash_iot | crash_phone');
        });
    }

    public function down(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropColumn('trigger_type');
        });
    }
};

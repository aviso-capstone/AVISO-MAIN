<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Values only known at the moment of detection, kept for the prediction
     * dataset: how sure the phone's GPS was about the spot (rows worse than
     * ~20 m are dropped when training) and the direction of travel (later
     * tells the same hazard apart from one on the opposite lane).
     */
    public function up(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->decimal('gps_accuracy', 7, 2)->nullable()->after('distance')->comment('GPS accuracy radius in meters');
            $table->decimal('heading', 5, 2)->nullable()->after('gps_accuracy')->comment('Direction of travel, degrees from north; null when stopped');
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropColumn(['gps_accuracy', 'heading']);
        });
    }
};

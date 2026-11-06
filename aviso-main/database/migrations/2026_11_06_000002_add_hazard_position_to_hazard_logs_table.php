<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hazard position: `latitude`/`longitude` become the hazard's own position (the
 * rider's GPS point moved forward by the camera distance). The rider's original
 * GPS point is kept in `rider_latitude`/`rider_longitude`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->decimal('rider_latitude', 10, 7)->nullable()->after('longitude')
                ->comment('Rider GPS point when detected (original latitude)');
            $table->decimal('rider_longitude', 10, 7)->nullable()->after('rider_latitude')
                ->comment('Rider GPS point when detected (original longitude)');
            $table->decimal('travel_heading', 5, 2)->nullable()->after('heading')
                ->comment('Direction of travel used for placement: GPS heading, or derived from the ride route');
            $table->string('position_source', 10)->nullable()->after('travel_heading')
                ->comment('gps | route | none; null = not placed yet');
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropColumn(['rider_latitude', 'rider_longitude', 'travel_heading', 'position_source']);
        });
    }
};

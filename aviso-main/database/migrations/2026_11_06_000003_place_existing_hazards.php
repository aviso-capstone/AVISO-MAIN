<?php

use App\Services\HazardPositionService;
use Illuminate\Database\Migrations\Migration;

/**
 * Moves every saved hazard that has not been placed yet from the rider's GPS
 * point to the hazard itself (see HazardPositionService). Safe to run again:
 * only rows with no position_source are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(HazardPositionService::class)->placeUnplaced();
    }

    /** Puts every hazard back on the rider's original GPS point. */
    public function down(): void
    {
        app(HazardPositionService::class)->restoreRiderPositions();
    }
};

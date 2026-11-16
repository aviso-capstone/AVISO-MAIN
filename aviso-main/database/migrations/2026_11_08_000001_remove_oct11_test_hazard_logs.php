<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Remove the 22 test saves made on Oct 11, 2026 between 12 and 1 AM
     * (Manila time) in Pasonanca with no ride attached. The team confirmed
     * they were test data. The prediction risk map (aviso_prediction.json)
     * was already trained without them.
     *
     * Every condition must match, so no other row can be removed. Hazard
     * reports on these rows (there were none) would be removed by their
     * foreign key cascade.
     */
    public function up(): void
    {
        DB::table('hazard_logs')
            ->whereBetween('id', [79, 100])
            ->where('detected_at', '>=', '2026-10-10 16:00:00') // Oct 11 00:00 Manila, in UTC
            ->where('detected_at', '<', '2026-10-10 17:00:00')  // Oct 11 01:00 Manila, in UTC
            ->whereNull('trip_id')
            ->where('area', 'Pasonanca')
            ->delete();
    }

    /**
     * Deleted rows cannot be restored (export them from Hazard Logs first).
     */
    public function down(): void
    {
        //
    }
};

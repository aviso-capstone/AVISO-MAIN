<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Traffic lights and signs became rider-only warnings: hazard_logs now
     * records just the three physical road hazards. Remove the light/sign
     * rows collected before that change.
     */
    public function up(): void
    {
        DB::table('hazard_logs')
            ->whereNotIn('type', ['Pothole', 'Road Excavation', 'Road Barrier'])
            ->delete();
    }

    /**
     * Deleted rows cannot be restored.
     */
    public function down(): void
    {
        //
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the old constraint
        DB::statement('ALTER TABLE hazard_logs DROP CONSTRAINT IF EXISTS hazard_logs_type_check');

        // 2. Migrate existing generic 'Traffic Light' records BEFORE adding the new constraint
        DB::statement("UPDATE hazard_logs SET type = 'Traffic Light Red' WHERE type = 'Traffic Light'");

        // 3. Add new constraint with all 3 traffic light variants
        DB::statement("
            ALTER TABLE hazard_logs
            ADD CONSTRAINT hazard_logs_type_check
            CHECK (type IN (
                'Pothole',
                'Road Excavation',
                'Road Barrier',
                'Traffic Sign',
                'Traffic Light Red',
                'Traffic Light Orange',
                'Traffic Light Green'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hazard_logs DROP CONSTRAINT IF EXISTS hazard_logs_type_check');
        DB::statement("
            ALTER TABLE hazard_logs
            ADD CONSTRAINT hazard_logs_type_check
            CHECK (type IN (
                'Pothole',
                'Road Excavation',
                'Road Barrier',
                'Traffic Sign',
                'Traffic Light'
            ))
        ");

        DB::statement("UPDATE hazard_logs SET type = 'Traffic Light' WHERE type IN ('Traffic Light Red', 'Traffic Light Orange', 'Traffic Light Green')");
    }
};

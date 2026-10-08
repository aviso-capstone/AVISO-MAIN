<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the old random codes (HAZ-DQNYNJ) with readable ones,
     * <PREFIX>-<YYYYMMDD>-<NNNN> (e.g. POT-20261001-0001): the detection day in
     * Philippine time and the type's running number for that day, assigned in
     * detection order. Same format HazardLogService generates for new rows;
     * kept inline so this migration never depends on later code changes.
     */
    public function up(): void
    {
        $prefixes = [
            'Pothole'         => 'POT',
            'Road Excavation' => 'EXC',
            'Road Barrier'    => 'BAR',
        ];

        $counters = [];

        DB::table('hazard_logs')
            ->whereIn('type', array_keys($prefixes))
            ->orderBy('detected_at')
            ->orderBy('id')
            ->get(['id', 'type', 'detected_at'])
            ->each(function ($row) use ($prefixes, &$counters) {
                $day    = Carbon::parse($row->detected_at, 'UTC')->setTimezone('Asia/Manila')->format('Ymd');
                $prefix = "{$prefixes[$row->type]}-{$day}-";
                $number = $counters[$prefix] = ($counters[$prefix] ?? 0) + 1;

                DB::table('hazard_logs')
                    ->where('id', $row->id)
                    ->update(['haz_code' => $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT)]);
            });
    }

    /**
     * The old random codes carried no information and cannot be restored.
     */
    public function down(): void
    {
        //
    }
};

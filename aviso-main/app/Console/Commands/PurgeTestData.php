<?php

namespace App\Console\Commands;

use App\Models\HazardLog;
use App\Models\Trip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes every hazard log and trip recorded during testing, after saving a JSON backup of both,
 * so real data gathering for the prediction model starts from a clean database.
 * Users, emergency alerts, contacts and barangays are never touched.
 */
class PurgeTestData extends Command
{
    protected $signature = 'aviso:purge-test-data {--force : Delete without asking for confirmation}';

    protected $description = 'Back up, then delete all hazard logs and trips recorded during testing';

    public function handle(): int
    {
        $hazards = HazardLog::count();
        $trips   = Trip::count();

        $this->table(['Data', 'Rows'], [['Hazard logs', $hazards], ['Trips', $trips]]);

        if ($hazards === 0 && $trips === 0) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Back up and permanently delete all of these?')) {
            $this->info('Cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        $path = 'backups/test-data-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($path, json_encode([
            'exported_at' => now()->toISOString(),
            'hazard_logs' => HazardLog::orderBy('id')->get()->toArray(),
            'trips'       => Trip::orderBy('id')->get()->toArray(),
        ], JSON_PRETTY_PRINT));
        $this->info('Backup saved to ' . Storage::disk('local')->path($path));

        // Hazard logs point to trips, so they go first.
        DB::transaction(function () {
            HazardLog::query()->delete();
            Trip::query()->delete();
        });

        $this->info("Deleted {$hazards} hazard logs and {$trips} trips.");

        return self::SUCCESS;
    }
}

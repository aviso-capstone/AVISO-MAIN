<?php

namespace App\Console\Commands;

use App\Models\EmergencyAlert;
use App\Models\EmergencyContact;
use App\Models\HazardLog;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the demo riders the old RiderSeeder created, plus every hazard log,
 * trip, SOS alert, contact and login token tied to them, so the admin side
 * only shows real activity during testing. The admin account and real riders
 * are never touched.
 */
class RemoveSeededData extends Command
{
    /** Usernames the former RiderSeeder created. */
    private const SEEDED_RIDERS = ['rider_juan', 'rider_maria', 'rider_pedro', 'rider_ana', 'rider_carlos'];

    /** Hazard rider codes with no account behind them, left over from early tests. */
    private const ORPHAN_RIDER_CODES = ['RIDER-016'];

    protected $signature = 'aviso:remove-seeded-data {--force : Delete without asking for confirmation}';

    protected $description = 'Delete the seeded demo riders and all their hazards, trips, alerts, contacts and tokens';

    public function handle(): int
    {
        $users     = User::whereIn('username', self::SEEDED_RIDERS)->where('role', 'rider')->get();
        $userIds   = $users->pluck('id');
        $codes     = array_merge(self::SEEDED_RIDERS, self::ORPHAN_RIDER_CODES);

        $hazards  = HazardLog::whereIn('rider_code', $codes)->orWhereIn('user_id', $userIds);
        $trips    = Trip::whereIn('rider_code', $codes)->orWhereIn('user_id', $userIds);
        $alerts   = EmergencyAlert::whereIn('user_id', $userIds);
        $contacts = EmergencyContact::whereIn('user_id', $userIds);

        $this->table(['Data', 'Rows to delete'], [
            ['Seeded rider accounts', $users->count()],
            ['Hazard logs', (clone $hazards)->count()],
            ['Trips', (clone $trips)->count()],
            ['SOS alerts', (clone $alerts)->count()],
            ['Emergency contacts', (clone $contacts)->count()],
        ]);

        if (!$this->option('force') && !$this->confirm('Delete these rows? This cannot be undone.')) {
            $this->warn('Nothing deleted.');

            return self::SUCCESS;
        }

        // Children first: hazard_logs/trips use nullOnDelete, so deleting the
        // users alone would leave their rows behind with user_id = null.
        DB::transaction(function () use ($hazards, $trips, $alerts, $contacts, $users) {
            $hazards->delete();
            $trips->delete();
            $alerts->delete();
            $contacts->delete();

            foreach ($users as $user) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        $this->info('Seeded demo data removed.');

        return self::SUCCESS;
    }
}

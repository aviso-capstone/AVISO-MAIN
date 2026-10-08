<?php

namespace App\Console\Commands;

use App\Models\EmergencyAlert;
use App\Models\HazardLog;
use App\Services\BarangayLocatorService;
use Illuminate\Console\Command;

/**
 * Re-resolves the barangay of every stored hazard and SOS alert from its saved
 * coordinates. Only barangay_code (and a hazard's area name) change — no row
 * is created or deleted — so it is safe to run again, e.g. after replacing
 * the boundary file with a newer PSGC release.
 */
class AssignBarangays extends Command
{
    protected $signature = 'hazards:assign-barangays';

    protected $description = 'Recompute the barangay of all hazard logs and emergency alerts from their coordinates';

    public function handle(BarangayLocatorService $locator): int
    {
        $hazardsUpdated = 0;
        $hazardsOutside = 0;

        HazardLog::query()->chunkById(500, function ($hazards) use ($locator, &$hazardsUpdated, &$hazardsOutside) {
            foreach ($hazards as $hazard) {
                $attributes = $locator->attributesFor((float) $hazard->latitude, (float) $hazard->longitude);

                if ($attributes['barangay_code'] === null) {
                    $hazardsOutside++;
                }

                $hazard->fill($attributes);

                if ($hazard->isDirty()) {
                    $hazard->save();
                    $hazardsUpdated++;
                }
            }
        });

        $alertsUpdated = 0;

        EmergencyAlert::query()->chunkById(500, function ($alerts) use ($locator, &$alertsUpdated) {
            foreach ($alerts as $alert) {
                $barangay = $locator->locate((float) $alert->latitude, (float) $alert->longitude);
                $alert->barangay_code = $barangay['code'] ?? null;

                if ($alert->isDirty()) {
                    $alert->save();
                    $alertsUpdated++;
                }
            }
        });

        $this->info("Hazard logs updated: {$hazardsUpdated} ({$hazardsOutside} outside Zamboanga City)");
        $this->info("Emergency alerts updated: {$alertsUpdated}");

        return self::SUCCESS;
    }
}

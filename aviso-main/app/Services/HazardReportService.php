<?php

namespace App\Services;

use App\Events\HazardReportReviewed;
use App\Events\HazardReportSubmitted;
use App\Models\HazardLog;
use App\Models\HazardReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Hazard no longer there": a rider sends a photo of a saved hazard's spot,
 * and an admin looks at it and either resolves the hazard or keeps it.
 */
class HazardReportService
{
    public function __construct(
        private SecureFileUploader $fileUploader,
        private HazardLogService $hazardLogService,
    ) {
        //
    }

    /**
     * Save a rider's report. Works from any distance (the photo is the proof);
     * the rider's distance from the hazard is kept so the admin can judge it.
     */
    public function submit(
        HazardLog $hazard,
        User $rider,
        UploadedFile $photo,
        ?float $latitude,
        ?float $longitude,
        ?string $note,
    ): HazardReport {
        if ($hazard->status !== HazardLog::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'hazard' => 'This hazard has already been resolved.',
            ]);
        }

        if ($hazard->reports()->where('user_id', $rider->id)->exists()) {
            throw ValidationException::withMessages([
                'hazard' => 'You already reported this hazard.',
            ]);
        }

        try {
            $path = $this->fileUploader->upload($photo, 'hazard-reports');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        $distance = ($latitude !== null && $longitude !== null)
            ? (int) round(HazardLogService::metersBetween(
                $latitude, $longitude, (float) $hazard->latitude, (float) $hazard->longitude,
            ))
            : null;

        $note = $note !== null ? trim($note) : null;

        $report = HazardReport::create([
            'hazard_log_id' => $hazard->id,
            'user_id'       => $rider->id,
            'photo_path'    => $path,
            'note'          => $note !== '' ? $note : null,
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'distance_m'    => $distance,
            'status'        => HazardReport::STATUS_PENDING,
        ]);

        $report->setRelation('hazard', $hazard);
        $report->setRelation('user', $rider);

        $this->broadcastSafely(new HazardReportSubmitted($report));

        return $report;
    }

    /**
     * The hazard is gone (fixed): resolve it and every other active save of the
     * same hazard, and close all their waiting reports as confirmed. The rows
     * stay in Hazard Logs as resolved.
     *
     * @return array{report_id: int, status: string, closed_report_ids: int[], resolved_hazard_ids: int[]}
     */
    public function confirm(HazardReport $report, User $admin): array
    {
        $this->ensurePending($report);

        [$closedIds, $resolvedIds] = DB::transaction(function () use ($report, $admin) {
            $hazard      = HazardLog::whereKey($report->hazard_log_id)->lockForUpdate()->firstOrFail();
            $resolvedIds = $hazard->status === HazardLog::STATUS_ACTIVE
                ? $this->hazardLogService->resolveGroup($hazard, $admin->id, HazardLog::REASON_FIXED)
                : [$hazard->id];

            $closedIds = HazardReport::pending()
                ->whereIn('hazard_log_id', $resolvedIds)
                ->pluck('id')
                ->all();

            HazardReport::whereIn('id', $closedIds)->update([
                'status'      => HazardReport::STATUS_CONFIRMED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'updated_at'  => now(),
            ]);

            return [$closedIds, $resolvedIds];
        });

        $report->refresh();
        $event = new HazardReportReviewed($report, $closedIds, $resolvedIds);
        $this->broadcastSafely($event);

        return $event->broadcastWith();
    }

    /**
     * The hazard is still there: close the report, the hazard stays active.
     *
     * @return array{report_id: int, status: string, closed_report_ids: int[], resolved_hazard_ids: int[]}
     */
    public function reject(HazardReport $report, User $admin): array
    {
        $this->ensurePending($report);

        $report->update([
            'status'      => HazardReport::STATUS_REJECTED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $event = new HazardReportReviewed($report, [$report->id], []);
        $this->broadcastSafely($event);

        return $event->broadcastWith();
    }

    /** Every report waiting for an admin, newest first, ready for the admin console. */
    public function pendingForAdmin(): array
    {
        return HazardReport::pending()
            ->with(['hazard', 'user'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (HazardReport $r) => self::toPayload($r))
            ->all();
    }

    /** The shape the admin console uses (live event and page load alike). */
    public static function toPayload(HazardReport $report): array
    {
        $hazard = $report->hazard;
        $rider  = $report->user;

        return [
            'id'            => $report->id,
            'hazard_log_id' => $report->hazard_log_id,
            'status'        => $report->status,
            'photo_url'     => $report->photo_url,
            'note'          => $report->note,
            'latitude'      => $report->latitude !== null ? (float) $report->latitude : null,
            'longitude'     => $report->longitude !== null ? (float) $report->longitude : null,
            'distance_m'    => $report->distance_m,
            'created_at'    => $report->created_at?->toISOString(),
            'rider_code'    => $rider?->username ?? (string) $report->user_id,
            'rider_name'    => $rider ? trim($rider->first_name . ' ' . $rider->last_name) : null,
            'hazard'        => $hazard ? [
                'id'          => $hazard->id,
                'haz_code'    => $hazard->haz_code,
                'type'        => $hazard->type,
                'area'        => $hazard->area,
                'latitude'    => (float) $hazard->latitude,
                'longitude'   => (float) $hazard->longitude,
                'confidence'  => $hazard->confidence !== null ? (float) $hazard->confidence : null,
                'rider_code'  => $hazard->rider_code,
                'status'      => $hazard->status,
                'detected_at' => $hazard->detected_at?->toISOString(),
            ] : null,
        ];
    }

    private function ensurePending(HazardReport $report): void
    {
        if ($report->status !== HazardReport::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'report' => 'This report was already reviewed.',
            ]);
        }
    }

    /** A Reverb outage must never fail the rider's upload or the admin's decision. */
    private function broadcastSafely(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Hazard report broadcast failed: ' . $e->getMessage());
        }
    }
}

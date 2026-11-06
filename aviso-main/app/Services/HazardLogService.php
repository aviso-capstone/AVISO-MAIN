<?php

namespace App\Services;

use App\Models\HazardLog;
use App\Models\HazardReport;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardLogService
{
    /** ~2 km in degrees of latitude: the box searched before falling back to all hazards. */
    private const NEAREST_SEARCH_DEG = 0.018;

    public function __construct(
        private BarangayLocatorService $barangayLocator,
        private TripService $tripService,
    )
    {
        //
    }

    private function applyFilters($query, array $filters)
    {
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->byType($filters['type']);
        }

        if (!empty($filters['barangay']) && $filters['barangay'] !== 'all') {
            $query->byBarangay($filters['barangay']);
        }

        if (in_array($filters['status'] ?? null, [HazardLog::STATUS_ACTIVE, HazardLog::STATUS_RESOLVED], true)) {
            $query->where('status', $filters['status']);
        }

        $query->detectedBetween($filters['from'] ?? null, $filters['to'] ?? null);

        return $query;
    }

    public function getPaginatedAdminLogs(array $filters): LengthAwarePaginator
    {
        $query = HazardLog::query();
        $this->applyFilters($query, $filters);

        $sort      = $filters['sort'] ?? '-detected_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column    = ltrim($sort, '-');

        $allowedSorts = ['haz_code', 'type', 'area', 'confidence', 'distance', 'detected_at', 'status'];
        if (in_array($column, $allowedSorts)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('detected_at', 'desc');
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = in_array($perPage, [10, 15, 25, 50]) ? $perPage : 15;

        $page = $query->paginate($perPage)->withQueryString();
        $this->attachNearestSameType($page->getCollection());
        $this->attachProofPhotos($page->getCollection());

        return $page;
    }

    /**
     * For resolved hazards: the rider's confirmed "no longer there" photo
     * (`proof_photo_url`, a short-lived private link) and who resolved it.
     */
    private function attachProofPhotos(iterable $logs): void
    {
        $logs = new \Illuminate\Database\Eloquent\Collection($logs);
        $resolvedIds = $logs->where('status', HazardLog::STATUS_RESOLVED)->pluck('id');

        $reports = $resolvedIds->isEmpty()
            ? collect()
            : \App\Models\HazardReport::whereIn('hazard_log_id', $resolvedIds)
                ->where('status', \App\Models\HazardReport::STATUS_CONFIRMED)
                ->latest()
                ->get()
                ->unique('hazard_log_id')
                ->keyBy('hazard_log_id');

        $logs->loadMissing('resolver:id,first_name,last_name');

        foreach ($logs as $log) {
            $log->setAttribute('proof_photo_url', $reports->get($log->id)?->photo_url);
            $log->setAttribute('resolved_by_name', $log->resolver
                ? trim($log->resolver->first_name . ' ' . $log->resolver->last_name)
                : null);
            $log->unsetRelation('resolver');
        }
    }

    public function getExportLogs(array $filters)
    {
        $query = HazardLog::query();
        $this->applyFilters($query, $filters);

        $sort      = $filters['sort'] ?? '-detected_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column    = ltrim($sort, '-');

        $allowedSorts = ['haz_code', 'type', 'area', 'confidence', 'distance', 'detected_at', 'status'];
        if (in_array($column, $allowedSorts)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('detected_at', 'desc');
        }

        $logs = $query->get();
        $this->attachNearestSameType($logs);

        return $logs;
    }

    public function getAdminStats(array $filters = []): array
    {
        $query = HazardLog::query();
        $this->applyFilters($query, $filters);

        $stats = (clone $query)->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $areaCounts = (clone $query)->selectRaw('area, count(*) as total')
            ->groupBy('area')
            ->pluck('total', 'area');

        return [
            'stats'      => $stats,
            'areaCounts' => $areaCounts,
        ];
    }

    public function processIncomingHazard(array $data): HazardLog
    {
        $detectedAt = isset($data['detected_at'])
            ? \Carbon\Carbon::parse($data['detected_at'])
            : now();

        // Idempotency: same rider + type within ±5s → return existing record.
        // Prevents duplicates from batch-sync re-sends and the rare race where
        // the immediate POST and the 30s batch sync both reach the server.
        // Safe with the mobile LOG_COOLDOWN of 8s: legitimate new detections are
        // always ≥8s apart, leaving a 3s gap between idempotency windows.
        $existing = HazardLog::where('user_id', $data['user_id'])
            ->where('type', $data['type'])
            ->whereBetween('detected_at', [
                $detectedAt->copy()->subSeconds(5),
                $detectedAt->copy()->addSeconds(5),
            ])
            ->first();

        if ($existing) {
            return $existing;
        }

        // The server decides the barangay from the real boundaries; anything
        // the device sent as an area is ignored.
        $data = array_merge($data, $this->barangayLocator->attributesFor(
            (float) $data['latitude'],
            (float) $data['longitude'],
        ));

        $data['status']      = HazardLog::STATUS_ACTIVE;
        $data['detected_at'] = $detectedAt;

        $data['trip_id'] = $this->resolveTripId($data, $detectedAt);

        if (isset($data['confidence']) && $data['confidence'] <= 1) {
            $data['confidence'] = $data['confidence'] * 100;
        }

        // Two riders can reach the same next number at the same moment; the
        // unique index on haz_code rejects the second, which then takes the
        // following number.
        for ($attempt = 1; ; $attempt++) {
            $data['haz_code'] = $this->generateHazardCode($data['type'], $detectedAt);

            try {
                return HazardLog::create($data);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The ride this detection belongs to: the trip id the phone sent if it is this rider's, otherwise the
     * rider's trip that was running at detection time (works for detections uploaded late).
     */
    private function resolveTripId(array $data, CarbonInterface $detectedAt): ?int
    {
        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        if (!empty($data['trip_id'])
            && Trip::whereKey($data['trip_id'])->where('user_id', $userId)->exists()) {
            return (int) $data['trip_id'];
        }

        return $this->tripService->tripAt((int) $userId, $detectedAt)?->id;
    }

    /**
     * Next readable code for a detection, e.g. POT-20261001-0001: the type
     * prefix, the detection day in Philippine time, and that type's running
     * number for the day. Uses the highest existing number, so deleted rows
     * never cause a reused code.
     */
    private function generateHazardCode(string $type, CarbonInterface $detectedAt): string
    {
        $prefix = HazardLog::CODE_PREFIXES[$type] . '-'
            . $detectedAt->copy()->setTimezone(HazardLog::CODE_TIMEZONE)->format('Ymd') . '-';

        $lastCode = HazardLog::where('haz_code', 'like', $prefix . '%')
            ->orderByDesc('haz_code')
            ->value('haz_code');

        $next = $lastCode ? (int) substr($lastCode, -4) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function resolve(HazardLog $hazard, int $resolvedBy, ?string $reason = null): bool
    {
        return $hazard->update([
            'status'          => HazardLog::STATUS_RESOLVED,
            'resolved_by'     => $resolvedBy,
            'resolved_at'     => now(),
            'resolved_reason' => $reason,
        ]);
    }

    /**
     * Resolve a hazard together with every other active save of it: the same
     * type within its group radius (pothole 10 m, excavation 25 m, barrier 30 m).
     * There is one row per save, so without this a fixed pothole saved five times
     * would leave four pins behind. Rows are only marked resolved, never deleted.
     *
     * @return int[] ids of every hazard log that was resolved
     */
    public function resolveGroup(HazardLog $hazard, int $resolvedBy, string $reason): array
    {
        $ids = $this->sameHazardIds($hazard);

        HazardLog::whereIn('id', $ids)->active()->update([
            'status'          => HazardLog::STATUS_RESOLVED,
            'resolved_by'     => $resolvedBy,
            'resolved_at'     => now(),
            'resolved_reason' => $reason,
            'updated_at'      => now(),
        ]);

        return $ids;
    }

    /**
     * Ids of the active saves that are the same real hazard as this one (always
     * includes the hazard itself).
     *
     * @return int[]
     */
    public function sameHazardIds(HazardLog $hazard): array
    {
        $radius = HazardLog::GROUP_RADIUS_M[$hazard->type] ?? 10;
        $lat    = (float) $hazard->latitude;
        $lng    = (float) $hazard->longitude;
        // A cheap box first (1 degree of latitude is about 111 km), then the exact distance.
        $box    = $radius / 111000 * 2;

        $ids = HazardLog::active()
            ->where('type', $hazard->type)
            ->whereBetween('latitude', [$lat - $box, $lat + $box])
            ->whereBetween('longitude', [$lng - $box, $lng + $box])
            ->get(['id', 'latitude', 'longitude'])
            ->filter(fn (HazardLog $other) => self::metersBetween(
                $lat, $lng, (float) $other->latitude, (float) $other->longitude,
            ) <= $radius)
            ->pluck('id')
            ->all();

        return array_values(array_unique([$hazard->id, ...$ids]));
    }

    public function getRiderLogs(): \Illuminate\Database\Eloquent\Collection
    {
        $logs = HazardLog::where('user_id', Auth::id())
            ->orderBy('detected_at', 'desc')
            ->limit(500)
            ->get();

        $this->attachNearestSameType($logs);

        return $logs;
    }

    /**
     * Hazard to Hazard Distance: for each log, the nearest OTHER active hazard
     * of the same type (from any rider), set as `nearest_hazard_code` and
     * `nearest_hazard_distance` (whole meters; both null when there is none).
     * Same type only: a barrier next to the excavation it guards is one site,
     * not two hazards close together. Calculated when read, so it stays
     * current as new hazards arrive.
     */
    public function attachNearestSameType(iterable $logs): void
    {
        $logs = collect($logs);
        if ($logs->isEmpty()) {
            return;
        }

        $candidates = HazardLog::active()
            ->whereIn('type', $logs->pluck('type')->unique()->values())
            ->get(['id', 'haz_code', 'type', 'latitude', 'longitude'])
            ->groupBy('type');

        foreach ($logs as $log) {
            $sameType = $candidates->get($log->type, collect());
            $lat = (float) $log->latitude;
            $lng = (float) $log->longitude;

            // Look nearby first (cheap box check); only scan everything when nothing is close.
            $nearest = $this->nearestOf($sameType, $log->id, $lat, $lng, self::NEAREST_SEARCH_DEG)
                ?? $this->nearestOf($sameType, $log->id, $lat, $lng, null);

            $log->setAttribute('nearest_hazard_code', $nearest['code'] ?? null);
            $log->setAttribute('nearest_hazard_distance', isset($nearest) ? (int) round($nearest['meters']) : null);
        }
    }

    /** @return array{code: string, meters: float}|null */
    private function nearestOf($hazards, int $selfId, float $lat, float $lng, ?float $boxDeg): ?array
    {
        $best = null;

        foreach ($hazards as $other) {
            if ($other->id === $selfId) {
                continue;
            }

            $oLat = (float) $other->latitude;
            $oLng = (float) $other->longitude;
            if ($boxDeg !== null && (abs($oLat - $lat) > $boxDeg || abs($oLng - $lng) > $boxDeg)) {
                continue;
            }

            $meters = self::metersBetween($lat, $lng, $oLat, $oLng);
            if ($best === null || $meters < $best['meters']) {
                $best = ['code' => $other->haz_code, 'meters' => $meters];
            }
        }

        return $best;
    }

    /** Great-circle (haversine) distance in meters. */
    public static function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * asin(sqrt($a)) * 6371000;
    }

    /**
     * Active hazards for the rider map and the known-hazard-ahead warning.
     * `heading` lets the app warn only riders going the same direction;
     * `reported_by_me` tells the app this rider already sent a "no longer
     * there" report for it.
     */
    public function getActiveForMap(?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        return HazardLog::active()
            ->withCount(['reports as pending_reports_count' => fn ($q) => $q->pending()])
            ->when($userId, fn ($q) => $q->withExists(['reports as reported_by_me' => fn ($r) => $r->where('user_id', $userId)]))
            ->orderBy('detected_at', 'desc')
            ->get(['id', 'haz_code', 'type', 'area', 'barangay_code', 'latitude', 'longitude', 'confidence', 'distance', 'gps_accuracy', 'heading', 'rider_code', 'detected_at']);
    }

    public function toCsvResponse(array $filters): StreamedResponse
    {
        $logs = $this->getExportLogs($filters);

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Code', 'Type', 'Area', 'Latitude', 'Longitude', 'Confidence (%)',
                'Camera Hazard Distance (m)', 'Hazard to Hazard Distance (m)', 'Nearest Same-Type Code',
                'GPS Accuracy (m)', 'Heading (deg)', 'Rider', 'Trip ID', 'Status', 'Detected At (PH time)',
                'Resolved At (PH time)', 'Resolved Reason',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->haz_code,
                    $log->type,
                    $log->area,
                    $log->latitude,
                    $log->longitude,
                    $log->confidence,
                    $log->distance,
                    $log->nearest_hazard_distance,
                    $log->nearest_hazard_code,
                    $log->gps_accuracy,
                    $log->heading,
                    $log->rider_code,
                    $log->trip_id,
                    $log->status,
                    self::localTime($log->detected_at),
                    self::localTime($log->resolved_at),
                    $log->resolved_reason,
                ]);
            }

            fclose($handle);
        }, 'hazard_logs.csv', ['Content-Type' => 'text/csv']);
    }

    public function toPdfResponse(array $filters)
    {
        $logs = $this->getExportLogs($filters);
        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.hazards-pdf', [
            'logs'      => $logs,
            'timezone'  => HazardLog::CODE_TIMEZONE,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('hazard_logs.pdf');
    }

    /** Export times in Philippine time (stored in UTC). */
    private static function localTime(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->setTimezone(HazardLog::CODE_TIMEZONE)->format('Y-m-d H:i:s') : '';
    }
}

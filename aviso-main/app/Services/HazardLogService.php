<?php

namespace App\Services;

use App\Models\HazardLog;
use App\Models\HazardReport;
use App\Models\Trip;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardLogService
{
    /** Fields of a map pin (getActiveForMap): the admin Live Map, the rider map and the known-hazard warning. */
    private const MAP_PIN_FIELDS = [
        'id', 'haz_code', 'type', 'area', 'barangay_code', 'latitude', 'longitude',
        'confidence', 'distance', 'gps_accuracy', 'heading', 'travel_heading', 'rider_code',
        'status', 'detected_at', 'times_seen', 'last_seen_at', 'member_ids',
        'pending_reports_count', 'reported_by_me',
    ];

    /** Same-hazard groups per status, built once per request (see groupsFor()). */
    private array $groupCache = [];

    public function __construct(
        private BarangayLocatorService $barangayLocator,
        private TripService $tripService,
        private HazardPositionService $hazardPosition,
        private HazardGroupService $hazardGroups,
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
        $this->attachTimesSeen($page->getCollection());
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
        $this->attachTimesSeen($logs);

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
        // The phone sends the rider's position. place() below keeps it in
        // rider_latitude/rider_longitude and moves latitude/longitude to the hazard.

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
                $log = HazardLog::create($data);
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }

        // Moved forward by the camera distance now when the phone sent its
        // direction (or the ride already ended); otherwise when the ride ends.
        // Placement never stops a hazard from being saved.
        try {
            $this->hazardPosition->place($log);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HazardLogService] placement failed', [
                'hazard_log_id' => $log->id,
                'error'         => $e->getMessage(),
            ]);
        }
        $this->groupCache = [];

        return $log;
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
     * Resolve a hazard together with every other active save of it (the same
     * hazard by HazardGroupService's rule). There is one row per save, so
     * without this a fixed pothole saved five times would leave four saves
     * behind. Rows are only marked resolved, never deleted.
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
        $group = $this->hazardGroups->index($this->groupsFor(HazardLog::STATUS_ACTIVE))[$hazard->id] ?? null;

        return array_values(array_unique([$hazard->id, ...($group['member_ids'] ?? [])]));
    }

    /**
     * Same-hazard groups of every save with this status (HazardGroupService).
     * Built once per request.
     */
    private function groupsFor(string $status): array
    {
        // All columns: a fixed list would break for the moments a deploy runs
        // before its migrations (the web server starts first).
        return $this->groupCache[$status] ??= $this->hazardGroups->group(
            HazardLog::where('status', $status)->get(),
        );
    }

    /**
     * Times Seen: how many times (rides) the same hazard was seen on the road,
     * set as `times_seen`, and when it was last seen (`last_seen_at`).
     */
    public function attachTimesSeen(iterable $logs): void
    {
        $indexes = [];

        foreach ($logs as $log) {
            $status = $log->status ?? HazardLog::STATUS_ACTIVE;
            $indexes[$status] ??= $this->hazardGroups->index($this->groupsFor($status));
            $group = $indexes[$status][$log->id] ?? null;

            $log->setAttribute('times_seen', $group['times_seen'] ?? 1);
            $log->setAttribute('last_seen_at', ($group['last_seen_at'] ?? $log->detected_at)?->toISOString());
        }
    }

    public function getRiderLogs(): \Illuminate\Database\Eloquent\Collection
    {
        $logs = HazardLog::where('user_id', Auth::id())
            ->orderBy('detected_at', 'desc')
            ->limit(500)
            ->get();

        $this->attachNearestSameType($logs);
        $this->attachTimesSeen($logs);

        return $logs;
    }

    /**
     * Hazard to Hazard Distance: for each log, the nearest OTHER active hazard
     * of the same type (from any rider), set as `nearest_hazard_code` and
     * `nearest_hazard_distance` (whole meters; both null when there is none).
     * Other saves of the same hazard are skipped: they are Times Seen, not a
     * second hazard close by (that would tell the prediction model hazards
     * always come in tight groups). Same type only: a barrier next to the
     * excavation it guards is one site, not two hazards close together.
     * Calculated when read, so it stays current as new hazards arrive.
     */
    public function attachNearestSameType(iterable $logs): void
    {
        $groups = null;

        foreach ($logs as $log) {
            $groups ??= $this->groupsFor(HazardLog::STATUS_ACTIVE);
            $nearest = $this->hazardGroups->nearestOtherHazard($log, $groups);

            $log->setAttribute('nearest_hazard_code', $nearest['code'] ?? null);
            $log->setAttribute('nearest_hazard_distance', isset($nearest) ? (int) round($nearest['meters']) : null);
        }
    }

    /** Great-circle (haversine) distance in meters. */
    public static function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return HazardPositionService::metersBetween($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Active hazards for the admin Live Map, the rider map and the known-hazard
     * warning: ONE pin per real hazard (all its saves grouped), placed at the
     * average position of its saves, with `times_seen`, `last_seen_at` and
     * `member_ids` (every save). The pin keeps the id and code of the hazard's
     * first save. `travel_heading` lets the app warn only riders going the same
     * direction; `pending_reports_count` and (for a rider) `reported_by_me`
     * cover reports on any save of the hazard.
     */
    public function getActiveForMap(?int $userId = null): EloquentCollection
    {
        $groups = $this->groupsFor(HazardLog::STATUS_ACTIVE);
        $allIds = [];
        foreach ($groups as $group) {
            array_push($allIds, ...$group['member_ids']);
        }

        $pending = $allIds === [] ? collect() : HazardReport::pending()
            ->whereIn('hazard_log_id', $allIds)
            ->selectRaw('hazard_log_id, count(*) as aggregate')
            ->groupBy('hazard_log_id')
            ->pluck('aggregate', 'hazard_log_id');

        $mine = ($userId && $allIds !== []) ? HazardReport::where('user_id', $userId)
            ->whereIn('hazard_log_id', $allIds)
            ->pluck('hazard_log_id')
            ->flip() : collect();

        $pins = array_map(
            fn (array $group) => self::mapPin($group, $pending, $userId ? $mine : null),
            $groups,
        );

        // Most recently seen first.
        usort($pins, fn (HazardLog $a, HazardLog $b) => strcmp((string) $b->last_seen_at, (string) $a->last_seen_at));

        return new EloquentCollection($pins);
    }

    /** One map pin for a hazard group: its first save, at the group's average position. */
    private static function mapPin(array $group, $pending, $mine): HazardLog
    {
        $pin = clone $group['anchor'];
        $pin->setAttribute('latitude', round($group['latitude'], 7));
        $pin->setAttribute('longitude', round($group['longitude'], 7));
        $pin->setAttribute('times_seen', $group['times_seen']);
        $pin->setAttribute('last_seen_at', $group['last_seen_at']?->toISOString());
        $pin->setAttribute('member_ids', $group['member_ids']);
        $pin->setAttribute('pending_reports_count', (int) array_sum(
            array_map(fn (int $id) => (int) ($pending[$id] ?? 0), $group['member_ids']),
        ));
        if ($mine !== null) {
            $pin->setAttribute('reported_by_me', collect($group['member_ids'])->contains(fn (int $id) => $mine->has($id)));
        }

        // Only what the maps need: riders receive these pins too, so never the
        // rider's own GPS point, user or trip of whoever saved it.
        return $pin->setVisible(self::MAP_PIN_FIELDS);
    }

    public function toCsvResponse(array $filters): StreamedResponse
    {
        $logs = $this->getExportLogs($filters);

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Code', 'Type', 'Area', 'Latitude', 'Longitude', 'Rider Latitude', 'Rider Longitude',
                'Position Source', 'Confidence (%)',
                'Camera Hazard Distance (m)', 'Hazard to Hazard Distance (m)', 'Nearest Same-Type Code',
                'Times Seen', 'Last Seen (PH time)',
                'GPS Accuracy (m)', 'Heading (deg)', 'Travel Heading (deg)', 'Rider', 'Trip ID', 'Status',
                'Detected At (PH time)', 'Resolved At (PH time)', 'Resolved Reason',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->haz_code,
                    $log->type,
                    $log->area,
                    $log->latitude,
                    $log->longitude,
                    $log->rider_latitude,
                    $log->rider_longitude,
                    $log->position_source,
                    $log->confidence,
                    $log->distance,
                    $log->nearest_hazard_distance,
                    $log->nearest_hazard_code,
                    $log->times_seen,
                    $log->last_seen_at ? self::localTime(Carbon::parse($log->last_seen_at)) : '',
                    $log->gps_accuracy,
                    $log->heading,
                    $log->travel_heading,
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

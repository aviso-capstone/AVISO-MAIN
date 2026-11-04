<?php

namespace App\Services;

use App\Models\EmergencyAlert;
use App\Models\RiderEvent;
use App\Models\Trip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The crash-detection log: every classification the IoT unit makes
 * (normal riding / hard braking / road bump / crash) with its sensor values.
 * Location comes from the rider's phone GPS, since the unit has none.
 *
 * The per-category min / average / max values are what the team uses to
 * set the unit's detection thresholds.
 */
class RiderEventService
{
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50];

    public function __construct(private BarangayLocatorService $barangayLocator)
    {
        //
    }

    /**
     * Records one event. Idempotent on event_uid: the app's offline queue and
     * the unit's own backup report may deliver the same event more than once.
     *
     * @param  array{event_type: string, latitude: float, longitude: float, acceleration_peak: float, event_uid?: string|null, vertical_g?: float|null, horizontal_g?: float|null, gyro_peak_dps?: float|null, tilt_deg?: float|null, detected_at?: string|null}  $data
     */
    public function record(User $rider, array $data, string $status = RiderEvent::STATUS_DETECTED): RiderEvent
    {
        if (! empty($data['event_uid'])) {
            $existing = RiderEvent::where('event_uid', $data['event_uid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $activeTrip = Trip::active()->where('user_id', $rider->id)->latest('started_at')->first();
        $place = $this->barangayLocator->attributesFor((float) $data['latitude'], (float) $data['longitude']);

        return RiderEvent::create([
            'event_uid' => $data['event_uid'] ?? null,
            'source' => RiderEvent::SOURCE_IOT,
            'user_id' => $rider->id,
            'rider_code' => $rider->username ?? (string) $rider->id,
            'trip_id' => $activeTrip?->id,
            'event_type' => $data['event_type'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'barangay_code' => $place['barangay_code'],
            'area' => $place['area'],
            'acceleration_peak' => $data['acceleration_peak'],
            'vertical_g' => $data['vertical_g'] ?? null,
            'horizontal_g' => $data['horizontal_g'] ?? null,
            'gyro_peak_dps' => $data['gyro_peak_dps'] ?? null,
            'tilt_deg' => $data['tilt_deg'] ?? null,
            'detected_at' => isset($data['detected_at']) ? Carbon::parse($data['detected_at']) : now(),
            'status' => $status,
        ]);
    }

    /**
     * @param  array{type?: string|null, search?: string|null, barangay?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function filteredQuery(array $filters, ?User $rider = null): Builder
    {
        return RiderEvent::query()
            ->when($rider, fn (Builder $q) => $q->where('user_id', $rider->id))
            ->when(
                ! empty($filters['type']) && $filters['type'] !== 'all',
                fn (Builder $q) => $q->ofType($filters['type']),
            )
            ->when(! empty($filters['search']), fn (Builder $q) => $q->where('rider_code', 'like', '%'.$filters['search'].'%'))
            ->when(
                ! empty($filters['barangay']) && $filters['barangay'] !== 'all',
                fn (Builder $q) => $q->where('barangay_code', $filters['barangay']),
            )
            ->detectedBetween($filters['from'] ?? null, $filters['to'] ?? null);
    }

    public function paginate(array $filters, ?User $rider = null): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;

        $page = $this->filteredQuery($filters, $rider)
            ->orderByDesc('detected_at')
            ->paginate($perPage)
            ->withQueryString();

        if ($rider) {
            $this->attachEmergencyResponse($page);
        }

        return $page;
    }

    /**
     * For the rider's Crash Logs: a crash that raised an SOS carries the same
     * event_uid as its emergency alert, so each crash row can show who
     * responded and how it ended. One extra query per page.
     */
    private function attachEmergencyResponse(LengthAwarePaginator $page): void
    {
        $uids = collect($page->items())
            ->pluck('event_uid')
            ->filter()
            ->unique()
            ->values();

        if ($uids->isEmpty()) {
            return;
        }

        $alerts = EmergencyAlert::whereIn('event_uid', $uids)->get()->keyBy('event_uid');

        foreach ($page->items() as $event) {
            $alert = $event->event_uid ? $alerts->get($event->event_uid) : null;
            $event->setAttribute('emergency_response', $alert ? EmergencyAlertService::riderStatus($alert) : null);
        }
    }

    /**
     * Count and min / avg / max of every sensor value per category — the
     * numbers used to place each detection threshold between categories.
     * The type filter is ignored so all four categories are always compared.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statsByType(array $filters, ?User $rider = null): array
    {
        $rows = $this->filteredQuery(array_merge($filters, ['type' => null]), $rider)
            ->selectRaw('event_type, count(*) as total,
                min(acceleration_peak) as g_min, avg(acceleration_peak) as g_avg, max(acceleration_peak) as g_max,
                min(vertical_g) as vg_min, avg(vertical_g) as vg_avg, max(vertical_g) as vg_max,
                min(horizontal_g) as hg_min, avg(horizontal_g) as hg_avg, max(horizontal_g) as hg_max,
                min(gyro_peak_dps) as gyro_min, avg(gyro_peak_dps) as gyro_avg, max(gyro_peak_dps) as gyro_max,
                min(tilt_deg) as tilt_min, avg(tilt_deg) as tilt_avg, max(tilt_deg) as tilt_max')
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        $round = fn ($v, int $d = 2) => $v === null ? null : round((float) $v, $d);

        return collect(RiderEvent::TYPE_LABELS)->map(function (string $label, string $type) use ($rows, $round) {
            $r = $rows->get($type);

            return [
                'type' => $type,
                'label' => $label,
                'total' => (int) ($r->total ?? 0),
                'g' => ['min' => $round($r?->g_min), 'avg' => $round($r?->g_avg), 'max' => $round($r?->g_max)],
                'vertical_g' => ['min' => $round($r?->vg_min), 'avg' => $round($r?->vg_avg), 'max' => $round($r?->vg_max)],
                'horizontal_g' => ['min' => $round($r?->hg_min), 'avg' => $round($r?->hg_avg), 'max' => $round($r?->hg_max)],
                'gyro_dps' => ['min' => $round($r?->gyro_min, 1), 'avg' => $round($r?->gyro_avg, 1), 'max' => $round($r?->gyro_max, 1)],
                'tilt_deg' => ['min' => $round($r?->tilt_min, 1), 'avg' => $round($r?->tilt_avg, 1), 'max' => $round($r?->tilt_max, 1)],
            ];
        })->values()->all();
    }

    /**
     * Text cells come partly from devices (event_uid), so a value starting
     * with =, +, -, @ would run as a formula when the CSV is opened in Excel.
     */
    private static function csvText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public function csvResponse(array $filters, ?User $rider = null): StreamedResponse
    {
        // id as tie-breaker keeps chunk pages stable when timestamps repeat.
        $query = $this->filteredQuery($filters, $rider)->orderBy('detected_at')->orderBy('id');
        $filename = 'crash_detection_logs_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Event ID', 'Category', 'Rider', 'Trip', 'Detected At', 'Latitude', 'Longitude',
                'Barangay', 'Peak G', 'Vertical G', 'Horizontal G', 'Gyro Peak (deg/s)', 'Tilt (deg)', 'Status',
            ]);

            // Chunked: "normal" summaries arrive every 10 s per rider, so the
            // log grows quickly and must not be loaded into memory at once.
            $query->chunk(1000, function ($events) use ($out) {
                foreach ($events as $e) {
                    fputcsv($out, [
                        self::csvText($e->event_uid ?? (string) $e->id),
                        self::csvText(RiderEvent::TYPE_LABELS[$e->event_type] ?? $e->event_type),
                        self::csvText($e->rider_code),
                        $e->trip_id,
                        $e->detected_at?->format('Y-m-d H:i:s'),
                        $e->latitude,
                        $e->longitude,
                        self::csvText($e->area),
                        $e->acceleration_peak,
                        $e->vertical_g,
                        $e->horizontal_g,
                        $e->gyro_peak_dps,
                        $e->tilt_deg,
                        self::csvText($e->status),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

<?php

namespace App\Services;

use App\Models\HazardLog;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardLogService
{
    public function __construct(private BarangayLocatorService $barangayLocator)
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

        return $query->paginate($perPage)->withQueryString();
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

        return $query->get();
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

        if (!empty($data['rider_code'])) {
            $activeTrip = Trip::active()->byRider($data['rider_code'])->first();
            if ($activeTrip) {
                $data['trip_id'] = $activeTrip->id;
            }
        }

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

    public function resolve(HazardLog $hazard, int $resolvedBy): bool
    {
        return $hazard->update([
            'status'      => HazardLog::STATUS_RESOLVED,
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
        ]);
    }

    public function getRiderLogs(): \Illuminate\Database\Eloquent\Collection
    {
        return HazardLog::where('user_id', Auth::id())
            ->orderBy('detected_at', 'desc')
            ->limit(500)
            ->get();
    }

    public function getActiveForMap(): \Illuminate\Database\Eloquent\Collection
    {
        return HazardLog::active()
            ->orderBy('detected_at', 'desc')
            ->get(['id', 'type', 'area', 'barangay_code', 'latitude', 'longitude', 'confidence', 'distance', 'rider_code', 'detected_at']);
    }

    public function toCsvResponse(array $filters): StreamedResponse
    {
        $logs = $this->getExportLogs($filters);

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Code', 'Type', 'Area', 'Latitude', 'Longitude',
                'Confidence', 'Distance (m)', 'Status', 'Detected At',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->haz_code,
                    $log->type,
                    $log->area,
                    $log->latitude,
                    $log->longitude,
                    $log->confidence . '%',
                    $log->distance,
                    $log->status,
                    $log->detected_at ? $log->detected_at->format('Y-m-d H:i:s') : '',
                ]);
            }

            fclose($handle);
        }, 'hazard_logs.csv', ['Content-Type' => 'text/csv']);
    }

    public function toPdfResponse(array $filters)
    {
        $logs = $this->getExportLogs($filters);
        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.hazards-pdf', ['logs' => $logs]);

        return $pdf->download('hazard_logs.pdf');
    }
}

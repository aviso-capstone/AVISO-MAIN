<?php

namespace App\Services;

use App\Models\HazardLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const TOP_BARANGAY_LIMIT = 10;

    /** Days follow Philippine time (the server clock is UTC, 8 hours behind). */
    private const TIMEZONE = HazardLog::CODE_TIMEZONE;

    public function getMetrics(): array
    {
        // 1. Stat Cards
        $avgConfidence = HazardLog::avg('confidence') ?? 0;

        $dashboardStats = [
            [
                'label' => 'Active Hazards',
                'value' => number_format($this->activeHazardsCount()),
                'sub' => 'Currently active across the city',
                'subVariant' => 'destructive',
            ],
            [
                'label' => 'Total Detections (Today)',
                'value' => number_format($this->todayDetectionsCount()),
                'sub' => 'Across potholes, road excavations and road barriers',
                'subVariant' => 'muted',
            ],
            [
                'label' => 'System Status',
                'value' => 'Online',
                'sub' => 'Model running · Avg. confidence ' . round($avgConfidence) . '%',
                'subVariant' => 'muted',
            ],
        ];

        // 2. Hazard Types Distribution (Donut)
        $hazardTypesDataRaw = HazardLog::select('type', DB::raw('count(*) as value'))
            ->groupBy('type')
            ->get();

        $typeMapping = [
            'Pothole'              => 'Potholes',
            'Road Excavation'      => 'Road Excavation',
            'Road Barrier'         => 'Road Barriers',
        ];

        $hazardTypesData = collect($typeMapping)->map(function ($chartName, $dbType) use ($hazardTypesDataRaw) {
            $record = $hazardTypesDataRaw->firstWhere('type', $dbType);
            return [
                'name' => $chartName,
                'value' => $record ? $record->value : 0,
            ];
        })->values()->toArray();

        // 3. Detection Accuracy by Type (Bar)
        $accuracyMapping = [
            'Pothole'              => ['name' => 'Pothole',    'key' => 'potholes'],
            'Road Excavation'      => ['name' => 'Excavation', 'key' => 'roadExcavation'],
            'Road Barrier'         => ['name' => 'Barriers',   'key' => 'roadBarriers'],
        ];

        $detectionAccuracyDataRaw = HazardLog::select('type', DB::raw('AVG(confidence) as accuracy'))
            ->groupBy('type')
            ->get();

        $detectionAccuracyData = collect($accuracyMapping)->map(function ($chart, $dbType) use ($detectionAccuracyDataRaw) {
            $record = $detectionAccuracyDataRaw->firstWhere('type', $dbType);
            return [
                'name' => $chart['name'],
                'key' => $chart['key'],
                'accuracy' => $record ? round($record->accuracy) : 0,
            ];
        })->values()->toArray();

        return [
            'dashboardStats' => $dashboardStats,
            'hazardTypesData' => $hazardTypesData,
            'detectionAccuracyData' => $detectionAccuracyData,
            'hazardsOverTimeData' => $this->hazardsOverTime(),
            'topBarangayHazards' => $this->getTopHazardBarangays(),
        ];
    }

    /**
     * City-wide numbers for the rider app home screen. Same counting as the
     * admin dashboard, so both always show the same numbers.
     *
     * @return array{active_hazards: int, today_detections: int, top_barangays: array}
     */
    public function getRiderOverview(): array
    {
        return [
            'active_hazards'   => $this->activeHazardsCount(),
            'today_detections' => $this->todayDetectionsCount(),
            'top_barangays'    => $this->getTopHazardBarangays(),
        ];
    }

    private function activeHazardsCount(): int
    {
        return HazardLog::where('status', 'active')->count();
    }

    private function todayDetectionsCount(): int
    {
        return HazardLog::where('detected_at', '>=', Carbon::today(self::TIMEZONE)->utc())->count();
    }

    /**
     * 7-day trend, grouped by Philippine day. The rows are grouped in PHP
     * (a week is a few hundred rows) so a detection at 7 AM Manila time is not
     * counted on the day before, which DATE() on the UTC column would do.
     */
    private function hazardsOverTime(): array
    {
        $firstDay = Carbon::today(self::TIMEZONE)->subDays(6);

        $byDay = HazardLog::where('detected_at', '>=', $firstDay->copy()->utc())
            ->get(['type', 'detected_at'])
            ->groupBy(fn (HazardLog $log) => $log->detected_at->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'));

        return collect(range(0, 6))->map(function (int $offset) use ($firstDay, $byDay) {
            $day = $firstDay->copy()->addDays($offset);
            $logs = $byDay->get($day->format('Y-m-d'), collect());

            return [
                'day' => $day->format('D'),
                'potholes' => $logs->where('type', HazardLog::TYPE_POTHOLE)->count(),
                'roadExcavation' => $logs->where('type', HazardLog::TYPE_ROAD_EXCAVATION)->count(),
                'roadBarriers' => $logs->where('type', HazardLog::TYPE_ROAD_BARRIER)->count(),
            ];
        })->toArray();
    }

    /**
     * Top barangays ranked by the number of physical road hazards recorded in
     * their area. One grouped query, pivoted in PHP. Barangays come from
     * hazard_logs.area, which BarangayLocatorService resolves from the real
     * PSGC boundaries; hazards outside the city have no barangay_code and are
     * left out.
     *
     * @return array<int, array{area: string, potholes: int, roadBarriers: int, roadExcavation: int, total: int}>
     */
    private function getTopHazardBarangays(): array
    {
        $raw = HazardLog::roadHazards()
            ->whereNotNull('barangay_code')
            ->select('area', 'type', DB::raw('count(*) as count'))
            ->groupBy('area', 'type')
            ->get();

        return $raw->groupBy('area')
            ->map(fn ($rows, $area) => [
                'area'           => $area,
                'potholes'       => (int) $rows->where('type', HazardLog::TYPE_POTHOLE)->sum('count'),
                'roadBarriers'   => (int) $rows->where('type', HazardLog::TYPE_ROAD_BARRIER)->sum('count'),
                'roadExcavation' => (int) $rows->where('type', HazardLog::TYPE_ROAD_EXCAVATION)->sum('count'),
                'total'          => (int) $rows->sum('count'),
            ])
            ->sortByDesc('total')
            ->take(self::TOP_BARANGAY_LIMIT)
            ->values()
            ->toArray();
    }
}

<?php

namespace App\Services;

use App\Models\HazardLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const TOP_BARANGAY_LIMIT = 3;

    public function getMetrics(): array
    {
        // 1. Stat Cards
        $activeHazardsCount = HazardLog::where('status', 'active')->count();
        $todayDetectionsCount = HazardLog::whereDate('detected_at', Carbon::today())->count();
        $avgConfidence = HazardLog::avg('confidence') ?? 0;

        $dashboardStats = [
            [
                'label' => 'Active Hazards',
                'value' => number_format($activeHazardsCount),
                'sub' => 'Currently active across the city',
                'subVariant' => 'destructive',
            ],
            [
                'label' => 'Total Detections (Today)',
                'value' => number_format($todayDetectionsCount),
                'sub' => 'Across all 7 hazard categories',
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
            'Pothole'              => 'Pothole',
            'Road Excavation'      => 'Excavation',
            'Road Barrier'         => 'Barriers',
        ];

        $detectionAccuracyDataRaw = HazardLog::select('type', DB::raw('AVG(confidence) as accuracy'))
            ->groupBy('type')
            ->get();

        $detectionAccuracyData = collect($accuracyMapping)->map(function ($chartName, $dbType) use ($detectionAccuracyDataRaw) {
            $record = $detectionAccuracyDataRaw->firstWhere('type', $dbType);
            return [
                'name' => $chartName,
                'accuracy' => $record ? round($record->accuracy) : 0,
            ];
        })->values()->toArray();

        // 4. Hazards Over Time (7-day Area Chart)
        $last7Days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $last7Days->push(Carbon::today()->subDays($i)->format('Y-m-d'));
        }

        $hazardsOverTimeRaw = HazardLog::select(
            DB::raw('DATE(detected_at) as date'),
            'type',
            DB::raw('count(*) as count')
        )
            ->where('detected_at', '>=', Carbon::today()->subDays(6))
            ->groupBy('date', 'type')
            ->get();

        $hazardsOverTimeData = $last7Days->map(function ($date) use ($hazardsOverTimeRaw) {
            $dayData = $hazardsOverTimeRaw->where('date', $date);
            return [
                'day' => Carbon::parse($date)->format('D'),
                'potholes' => $dayData->where('type', 'Pothole')->sum('count'),
                'roadExcavation' => $dayData->where('type', 'Road Excavation')->sum('count'),
                'roadBarriers' => $dayData->where('type', 'Road Barrier')->sum('count'),
            ];
        })->toArray();

        return [
            'dashboardStats' => $dashboardStats,
            'hazardTypesData' => $hazardTypesData,
            'detectionAccuracyData' => $detectionAccuracyData,
            'hazardsOverTimeData' => $hazardsOverTimeData,
            'topBarangayHazards' => $this->getTopHazardBarangays(),
        ];
    }

    /**
     * Top barangays ranked by the number of physical road hazards recorded in
     * their area. One grouped query, pivoted in PHP — same shape as the 7-day
     * trend above. Barangays come from hazard_logs.area, which
     * BarangayLocatorService resolves from the real PSGC boundaries; hazards
     * outside the city have no barangay_code and are left out.
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

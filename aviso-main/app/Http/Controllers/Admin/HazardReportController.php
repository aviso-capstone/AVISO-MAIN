<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HazardReport;
use App\Services\HazardReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin review of riders' "hazard no longer there" reports (Live Map panel). */
class HazardReportController extends Controller
{
    public function __construct(private HazardReportService $hazardReportService)
    {
    }

    public function pending(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'reports' => $this->hazardReportService->pendingForAdmin(),
        ]);
    }

    /** The hazard is gone: resolve it (and its duplicate saves). */
    public function confirm(Request $request, HazardReport $report): JsonResponse
    {
        return response()->json([
            'success' => true,
            'review'  => $this->hazardReportService->confirm($report, $request->user()),
        ]);
    }

    /** The hazard is still there: close the report, keep the hazard active. */
    public function reject(Request $request, HazardReport $report): JsonResponse
    {
        return response()->json([
            'success' => true,
            'review'  => $this->hazardReportService->reject($report, $request->user()),
        ]);
    }
}

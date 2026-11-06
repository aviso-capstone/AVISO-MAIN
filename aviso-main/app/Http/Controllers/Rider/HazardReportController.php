<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\StoreHazardReportRequest;
use App\Models\HazardLog;
use App\Services\HazardReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class HazardReportController extends Controller
{
    public function __construct(private HazardReportService $hazardReportService)
    {
    }

    /** A rider reports a saved hazard as no longer there, with a photo as proof. */
    public function store(StoreHazardReportRequest $request, HazardLog $hazard): JsonResponse
    {
        $data = $request->validated();

        $report = $this->hazardReportService->submit(
            $hazard,
            Auth::user(),
            $request->file('photo'),
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['note'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Report sent. An admin will check it.',
            'data'    => [
                'id'            => $report->id,
                'hazard_log_id' => $report->hazard_log_id,
                'status'        => $report->status,
                'distance_m'    => $report->distance_m,
            ],
        ], 201);
    }
}

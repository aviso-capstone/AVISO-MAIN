<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\StoreHazardLogRequest;
use App\Services\HazardLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class HazardLogController extends Controller
{
    public function __construct(private HazardLogService $hazardLogService)
    {
    }

    public function index(): JsonResponse
    {
        $logs = $this->hazardLogService->getRiderLogs();

        return response()->json($logs);
    }

    public function active(): JsonResponse
    {
        $hazards = $this->hazardLogService->getActiveForMap();

        return response()->json($hazards);
    }

    public function store(StoreHazardLogRequest $request): JsonResponse
    {
        $data               = $request->validated();
        $data['user_id']    = Auth::id();
        $data['rider_code'] = Auth::user()->username ?? (string) Auth::id();

        $log = $this->hazardLogService->processIncomingHazard($data);

        return response()->json([
            'success' => true,
            'message' => 'Hazard log created successfully.',
            'data'    => $log,
        ], 201);
    }
}

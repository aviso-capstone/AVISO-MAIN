<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    /** City-wide overview for the rider home screen (no personal data). */
    public function overview(): JsonResponse
    {
        return response()->json($this->dashboardService->getRiderOverview());
    }
}

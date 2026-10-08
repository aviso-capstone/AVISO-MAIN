<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmergencyAlert;
use App\Models\User;
use App\Services\EmergencyAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyAlertController extends Controller
{
    public function __construct(
        protected EmergencyAlertService $emergencyAlertService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'per_page']);

        return Inertia::render('main/SosLogs', [
            'riders'  => $this->emergencyAlertService->getHistoryGroupedByRider($filters),
            'stats'   => $this->emergencyAlertService->getAdminStats(),
            'filters' => $filters,
        ]);
    }

    public function history(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'alerts'  => $this->emergencyAlertService->getAlertsForRider($user),
        ]);
    }

    public function resolve(EmergencyAlert $alert): JsonResponse
    {
        return response()->json([
            'success' => true,
            'alert'   => $this->emergencyAlertService->resolve($alert),
        ]);
    }
}

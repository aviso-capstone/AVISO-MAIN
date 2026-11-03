<?php

namespace App\Http\Controllers\Admin;

use App\Events\EmergencyAlertTriggered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchEmergencyAlertRequest;
use App\Http\Requests\Admin\ResolveEmergencyAlertRequest;
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

    public function dispatch(DispatchEmergencyAlertRequest $request, EmergencyAlert $alert): JsonResponse
    {
        $alert = $this->emergencyAlertService->dispatch($alert, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'alert'   => (new EmergencyAlertTriggered($alert))->broadcastWith(),
        ]);
    }

    public function onScene(EmergencyAlert $alert): JsonResponse
    {
        $alert = $this->emergencyAlertService->markOnScene($alert);

        return response()->json([
            'success' => true,
            'alert'   => (new EmergencyAlertTriggered($alert))->broadcastWith(),
        ]);
    }

    public function resolve(ResolveEmergencyAlertRequest $request, EmergencyAlert $alert): JsonResponse
    {
        $alert = $this->emergencyAlertService->resolve($alert, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'alert'   => (new EmergencyAlertTriggered($alert))->broadcastWith(),
        ]);
    }
}

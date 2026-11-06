<?php

namespace App\Http\Controllers\Admin;

use App\Events\EmergencyAlertTriggered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DispatchEmergencyAlertRequest;
use App\Http\Requests\Admin\ResolveEmergencyAlertRequest;
use App\Http\Requests\Admin\SosAlertFilterRequest;

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

    public function index(SosAlertFilterRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'status', 'per_page']);

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

        return $this->stepResponse($alert, EmergencyAlertService::STEP_DISPATCHED);
    }

    public function onScene(Request $request, EmergencyAlert $alert): JsonResponse
    {
        $alreadyOnScene = $alert->on_scene_at !== null;
        $alert = $this->emergencyAlertService->markOnScene($alert, $request->user());

        // A repeat click changes nothing, so the contacts are not texted twice.
        return $this->stepResponse($alert, $alreadyOnScene ? null : EmergencyAlertService::STEP_ON_SCENE);
    }

    public function resolve(ResolveEmergencyAlertRequest $request, EmergencyAlert $alert): JsonResponse
    {
        $alert = $this->emergencyAlertService->resolve($alert, $request->validated(), $request->user());

        return $this->stepResponse($alert, EmergencyAlertService::STEP_RESOLVED);
    }

    /** The updated alert, plus how many emergency contacts got the SMS update. */
    private function stepResponse(EmergencyAlert $alert, ?string $step): JsonResponse
    {
        return response()->json([
            'success' => true,
            'alert'   => (new EmergencyAlertTriggered($alert))->broadcastWith(),
            'sms'     => $step ? $this->emergencyAlertService->textContactsAboutStep($alert, $step) : null,
        ]);
    }
}

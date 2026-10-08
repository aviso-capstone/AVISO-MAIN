<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\SosRequest;
use App\Services\EmergencyAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmergencyController extends Controller
{
    public function __construct(private EmergencyAlertService $emergencyAlertService)
    {
        //
    }

    public function sos(SosRequest $request): JsonResponse
    {
        $alert = $this->emergencyAlertService->triggerSos(
            Auth::user(),
            $request->latitude,
            $request->longitude,
            $request->input('triggered_at'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Emergency SOS triggered.',
            'data'    => [
                'id'           => $alert->id,
                'triggered_at' => $alert->triggered_at->toISOString(),
                'status'       => $alert->status,
            ],
        ], 201);
    }
}

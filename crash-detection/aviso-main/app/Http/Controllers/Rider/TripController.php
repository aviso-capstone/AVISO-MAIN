<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\EndTripRequest;
use App\Http\Requests\Rider\StartTripRequest;
use App\Http\Requests\Rider\UpdateLocationRequest;
use App\Models\Trip;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TripController extends Controller
{
    public function __construct(private TripService $tripService)
    {
    }

    public function start(StartTripRequest $request): JsonResponse
    {
        $data               = $request->validated();
        $data['rider_code'] = Auth::user()->username ?? (string) Auth::id();
        $data['user_id']    = Auth::id();
        $trip               = $this->tripService->startTrip($data);

        return response()->json([
            'success' => true,
            'message' => 'Trip started successfully.',
            'data'    => $trip,
        ], 201);
    }

    public function updateLocation(UpdateLocationRequest $request, Trip $trip): JsonResponse
    {
        try {
            $this->tripService->updateLocation(
                $trip,
                (float) $request->latitude,
                (float) $request->longitude,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Location updated.']);
    }

    public function end(EndTripRequest $request, Trip $trip): JsonResponse
    {
        try {
            $this->tripService->endTrip(
                $trip,
                (float) $request->latitude,
                (float) $request->longitude,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Trip ended successfully.',
            'data'    => $trip->fresh(),
        ]);
    }
}

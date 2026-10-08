<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;

class TripController extends Controller
{
    protected $tripService;

    public function __construct(TripService $tripService)
    {
        $this->tripService = $tripService;
    }

    /**
     * Return all active rider trips for the admin live map.
     *
     * The admin map front-end polls this endpoint every few seconds
     * to refresh the rider pins and route lines on the map.
     *
     * Each trip includes: rider_code, start_lat/lng (origin pin),
     * current_lat/lng (live position), and started_at.
     */
    public function activeRiders(): JsonResponse
    {
        $trips = $this->tripService->getActiveTrips();

        return response()->json([
            'success' => true,
            'data'    => $trips,
        ]);
    }
}

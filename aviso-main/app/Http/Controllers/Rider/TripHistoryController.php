<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TripHistoryController extends Controller
{
    public function __construct(private TripService $tripService)
    {
    }

    public function index(): JsonResponse
    {
        $riderCode = Auth::user()->username ?? (string) Auth::id();

        return response()->json(
            $this->tripService->getRiderHistory($riderCode)
        );
    }

    public function show(Trip $trip): JsonResponse
    {
        $riderCode = Auth::user()->username ?? (string) Auth::id();

        abort_if($trip->rider_code !== $riderCode, 403, 'Forbidden.');

        return response()->json(
            $this->tripService->getTripWithHazards($trip)
        );
    }
}

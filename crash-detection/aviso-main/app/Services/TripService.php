<?php

namespace App\Services;

use App\Events\RiderLocationUpdated;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TripService
{
    public function startTrip(array $data): Trip
    {
        $trip = Trip::create([
            'user_id'     => $data['user_id'] ?? null,
            'rider_code'  => $data['rider_code'],
            'start_lat'   => $data['latitude'],
            'start_lng'   => $data['longitude'],
            'current_lat' => $data['latitude'],
            'current_lng' => $data['longitude'],
            'status'      => Trip::STATUS_ACTIVE,
            'started_at'  => now(),
        ]);

        broadcast(new RiderLocationUpdated($trip));

        return $trip;
    }

    public function updateLocation(Trip $trip, float $lat, float $lng): bool
    {
        if ($trip->status !== Trip::STATUS_ACTIVE) {
            throw new \RuntimeException('Cannot update location of a trip that has already ended.');
        }

        $waypoints   = $trip->route_points ?? [];
        $waypoints[] = ['lat' => $lat, 'lng' => $lng, 'ts' => now()->toISOString()];

        $updated = $trip->update([
            'current_lat'  => $lat,
            'current_lng'  => $lng,
            'route_points' => $waypoints,
        ]);

        broadcast(new RiderLocationUpdated($trip->fresh()));

        return $updated;
    }

    public function endTrip(Trip $trip, float $lat, float $lng): bool
    {
        if ($trip->status !== Trip::STATUS_ACTIVE) {
            throw new \RuntimeException('This trip has already ended.');
        }

        $waypoints   = $trip->route_points ?? [];
        $waypoints[] = ['lat' => $lat, 'lng' => $lng, 'ts' => now()->toISOString()];

        $endedAt         = now();
        $distanceKm      = $this->computeDistanceKm($waypoints);
        $durationMinutes = (int) $trip->started_at->diffInMinutes($endedAt);

        return $trip->update([
            'current_lat'       => $lat,
            'current_lng'       => $lng,
            'end_lat'           => $lat,
            'end_lng'           => $lng,
            'route_points'      => $waypoints,
            'status'            => Trip::STATUS_ENDED,
            'ended_at'          => $endedAt,
            'total_distance_km' => $distanceKm,
            'duration_minutes'  => $durationMinutes,
        ]);
    }

    public function getActiveTrips(): Collection
    {
        return Trip::active()
            ->orderBy('started_at', 'desc')
            ->get();
    }

    public function getRiderHistory(string $riderCode): LengthAwarePaginator
    {
        return Trip::where('status', Trip::STATUS_ENDED)
            ->byRider($riderCode)
            ->orderBy('started_at', 'desc')
            ->paginate(20, ['id', 'rider_code', 'start_lat', 'start_lng', 'end_lat', 'end_lng', 'started_at', 'ended_at', 'total_distance_km', 'duration_minutes']);
    }

    public function getTripWithHazards(Trip $trip): array
    {
        $hazards = $trip->hazardLogs()
            ->orderBy('detected_at')
            ->get(['id', 'haz_code', 'type', 'area', 'latitude', 'longitude', 'confidence', 'detected_at']);

        return [
            'trip'    => $trip,
            'hazards' => $hazards,
        ];
    }

    private function computeDistanceKm(array $routePoints): float
    {
        $total = 0.0;
        $count = count($routePoints);

        for ($i = 1; $i < $count; $i++) {
            $lat1 = deg2rad((float) $routePoints[$i - 1]['lat']);
            $lng1 = deg2rad((float) $routePoints[$i - 1]['lng']);
            $lat2 = deg2rad((float) $routePoints[$i]['lat']);
            $lng2 = deg2rad((float) $routePoints[$i]['lng']);

            $dlat = $lat2 - $lat1;
            $dlng = $lng2 - $lng1;

            $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
            $total += 2 * asin(sqrt($a)) * 6371;
        }

        return round($total, 3);
    }
}

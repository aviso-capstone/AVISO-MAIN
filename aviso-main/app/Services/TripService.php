<?php

namespace App\Services;

use App\Events\RiderLocationUpdated;
use App\Models\Trip;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TripService
{
    public function startTrip(array $data): Trip
    {
        // A rider rides one trip at a time: anything still open (app closed mid-ride, lost signal) is
        // closed first, so detections always link to the right trip.
        $this->closeOpenTrips($data['user_id'] ?? null, $data['rider_code']);

        $trip = Trip::create([
            'user_id'     => $data['user_id'] ?? null,
            'rider_code'  => $data['rider_code'],
            'start_lat'   => $data['latitude'],
            'start_lng'   => $data['longitude'],
            'current_lat' => $data['latitude'],
            'current_lng' => $data['longitude'],
            'status'      => Trip::STATUS_ACTIVE,
            'started_at'  => isset($data['started_at']) ? Carbon::parse($data['started_at']) : now(),
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

    /**
     * @param  string|null  $endedAt      the phone's end time (rides uploaded later keep their real time)
     * @param  array|null   $routePoints  the phone's full route; used when it has more points than the server got
     */
    public function endTrip(Trip $trip, float $lat, float $lng, ?string $endedAt = null, ?array $routePoints = null): bool
    {
        if ($trip->status !== Trip::STATUS_ACTIVE) {
            throw new \RuntimeException('This trip has already ended.');
        }

        $endedAt = $endedAt ? Carbon::parse($endedAt) : now();
        if ($endedAt->lt($trip->started_at)) {
            $endedAt = $trip->started_at->copy();
        }

        $waypoints = $trip->route_points ?? [];
        if ($routePoints && count($routePoints) > count($waypoints)) {
            $waypoints = array_map(
                fn (array $p) => ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']],
                $routePoints,
            );
        }
        $waypoints[] = ['lat' => $lat, 'lng' => $lng, 'ts' => $endedAt->toISOString()];

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
            ->paginate(50, ['id', 'rider_code', 'start_lat', 'start_lng', 'end_lat', 'end_lng', 'route_points', 'started_at', 'ended_at', 'total_distance_km', 'duration_minutes']);
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

    /** Ends every trip of this rider still marked active, at its last known point. */
    private function closeOpenTrips(?int $userId, string $riderCode): void
    {
        $open = Trip::active()
            ->where(fn ($q) => $userId ? $q->where('user_id', $userId) : $q->where('rider_code', $riderCode))
            ->get();

        foreach ($open as $trip) {
            $waypoints = $trip->route_points ?? [];
            $last      = end($waypoints) ?: null;
            $endedAt   = isset($last['ts']) ? Carbon::parse($last['ts']) : $trip->started_at->copy();

            $trip->update([
                'end_lat'           => $trip->current_lat,
                'end_lng'           => $trip->current_lng,
                'status'            => Trip::STATUS_ENDED,
                'ended_at'          => $endedAt,
                'total_distance_km' => $this->computeDistanceKm($waypoints),
                'duration_minutes'  => (int) $trip->started_at->diffInMinutes($endedAt),
            ]);
        }
    }

    /**
     * The rider's trip that was running at $at: started before it and not ended before it (newest first).
     * Links detections to the right trip even when they are uploaded late.
     */
    public function tripAt(int $userId, CarbonInterface $at): ?Trip
    {
        return Trip::where('user_id', $userId)
            ->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $at))
            ->orderByDesc('started_at')
            ->first();
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

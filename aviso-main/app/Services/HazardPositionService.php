<?php

namespace App\Services;

use App\Models\HazardLog;
use App\Models\Trip;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Hazard position: the phone saves where the RIDER was when the camera saw the
 * hazard, but the hazard is ahead of the rider by the camera distance. This
 * moves the point forward by that distance in the direction of travel.
 *
 * The direction comes from the phone's GPS heading, otherwise from the ride's
 * stored route; when neither is clear the point is not moved. The rider's
 * original point is kept in rider_latitude / rider_longitude.
 */
class HazardPositionService
{
    public const SOURCE_GPS   = 'gps';
    public const SOURCE_ROUTE = 'route';
    public const SOURCE_NONE  = 'none';

    /** A point is never moved farther than this (m): longer camera distances are rough estimates. */
    public const MAX_SHIFT_M = 30;
    /** Camera distances below this are not worth a move (m). */
    private const MIN_SHIFT_M = 1;

    /** Route points this many seconds around the detection give the direction. */
    private const ROUTE_TIME_WINDOW_S = 20;
    /** The two route points must be at least this far apart for a usable direction (m). */
    private const ROUTE_MIN_SPAN_M = 5;
    /** With route times: the rider's point must be this close to one of the two route points (m). */
    private const ROUTE_TIME_MAX_OFFSET_M = 40;
    /** Without route times: the rider's point must be this close to the route (m). */
    private const ROUTE_MAX_OFFSET_M = 15;
    /**
     * The route is smoothed to dots at least this far apart (m) before reading
     * its shape: while slow or stopped the phone adds dots ~1 m apart whose
     * jitter points every way.
     */
    private const ROUTE_STEP_M = 8;
    /**
     * Without route times: route pieces near the point that differ by more than
     * this (°) mean the rider passed there in another direction too (there and
     * back, or a corner), so the direction is unclear and the point is not moved.
     */
    private const ROUTE_AMBIGUOUS_ANGLE = 90;

    private const EARTH_RADIUS_M = 6371000;

    public function __construct(private BarangayLocatorService $barangayLocator)
    {
    }

    /**
     * The columns to store for this hazard, or null when it must wait for the
     * end of its ride (no GPS heading and the route is not complete yet).
     *
     * @param  array|null  $routePoints    the ride's route_points
     * @param  bool        $routeComplete  false while the ride is still active
     */
    public function placement(HazardLog $log, ?array $routePoints, bool $routeComplete = true): ?array
    {
        $riderLat = (float) ($log->rider_latitude ?? $log->latitude);
        $riderLng = (float) ($log->rider_longitude ?? $log->longitude);

        $heading = $log->heading !== null ? (float) $log->heading : null;
        $source  = $heading !== null ? self::SOURCE_GPS : null;

        if ($heading === null) {
            if (! $routeComplete) {
                return null;
            }
            if ($routePoints) {
                $heading = $this->headingFromRoute($routePoints, $riderLat, $riderLng, $log->detected_at);
                $source  = $heading !== null ? self::SOURCE_ROUTE : null;
            }
        }

        $distance = $log->distance !== null ? (float) $log->distance : null;
        $lat = $riderLat;
        $lng = $riderLng;

        if ($heading !== null && $distance !== null && $distance >= self::MIN_SHIFT_M) {
            [$lat, $lng] = self::destinationPoint($riderLat, $riderLng, $heading, min($distance, self::MAX_SHIFT_M));
        } else {
            $source = self::SOURCE_NONE;
        }

        return array_merge([
            'rider_latitude'  => $riderLat,
            'rider_longitude' => $riderLng,
            'latitude'        => round($lat, 7),
            'longitude'       => round($lng, 7),
            'travel_heading'  => $heading !== null ? round($heading, 2) : null,
            'position_source' => $source,
        ], $this->barangayLocator->attributesFor($lat, $lng));
    }

    /**
     * Places one hazard and saves it. Returns false when it has to wait for the
     * end of its ride (the rider's original point is still recorded).
     */
    public function place(HazardLog $log, ?Trip $trip = null): bool
    {
        if ($trip === null && $log->trip_id) {
            $trip = Trip::find($log->trip_id, ['id', 'status', 'route_points']);
        }

        $routeComplete = $trip === null || $trip->status !== Trip::STATUS_ACTIVE;
        $attributes    = $this->placement($log, $trip?->route_points, $routeComplete);

        if ($attributes === null) {
            if ($log->rider_latitude === null) {
                $log->forceFill([
                    'rider_latitude'  => $log->latitude,
                    'rider_longitude' => $log->longitude,
                ])->save();
            }
            return false;
        }

        $log->forceFill($attributes)->save();

        return true;
    }

    /** Places the hazards of a ride that just ended. Returns how many were placed. */
    public function placeTrip(Trip $trip): int
    {
        $placed = 0;

        HazardLog::where('trip_id', $trip->id)
            ->whereNull('position_source')
            ->get()
            ->each(function (HazardLog $log) use ($trip, &$placed) {
                if ($this->place($log, $trip)) {
                    $placed++;
                }
            });

        return $placed;
    }

    /** Places every hazard not placed yet (used once on deploy; safe to run again). */
    public function placeUnplaced(): int
    {
        $placed = 0;

        HazardLog::whereNull('position_source')->chunkById(200, function ($logs) use (&$placed) {
            $trips = Trip::whereIn('id', $logs->pluck('trip_id')->filter()->unique())
                ->get(['id', 'status', 'route_points'])
                ->keyBy('id');

            foreach ($logs as $log) {
                if ($this->place($log, $log->trip_id ? $trips->get($log->trip_id) : null)) {
                    $placed++;
                }
            }
        });

        return $placed;
    }

    /** Undo: every hazard back on the rider's original GPS point, with its barangay. */
    public function restoreRiderPositions(): void
    {
        HazardLog::whereNotNull('rider_latitude')->chunkById(200, function ($logs) {
            foreach ($logs as $log) {
                $lat = (float) $log->rider_latitude;
                $lng = (float) $log->rider_longitude;
                $log->forceFill(array_merge([
                    'latitude'        => $lat,
                    'longitude'       => $lng,
                    'travel_heading'  => null,
                    'position_source' => null,
                ], $this->barangayLocator->attributesFor($lat, $lng)))->save();
            }
        });
    }

    /**
     * Direction of travel at this point of the ride, in degrees from north, or
     * null when the route does not show it clearly.
     */
    public function headingFromRoute(array $routePoints, float $lat, float $lng, ?CarbonInterface $at): ?float
    {
        $points = self::normalizeRoute($routePoints);
        if (count($points) < 2) {
            return null;
        }

        if ($at !== null) {
            $byTime = $this->headingByTime($points, $lat, $lng, $at);
            if ($byTime !== null) {
                return $byTime;
            }
        }

        return $this->headingByShape($points, $lat, $lng);
    }

    /** The route points just before and just after the detection time. */
    private function headingByTime(array $points, float $lat, float $lng, CarbonInterface $at): ?float
    {
        $time   = $at->getTimestamp();
        $inside = fn (int $i) => $points[$i]['ts'] !== null
            && abs($points[$i]['ts'] - $time) <= self::ROUTE_TIME_WINDOW_S;

        $before = null;
        $after  = null;
        foreach ($points as $i => $p) {
            if ($p['ts'] === null) {
                continue;
            }
            $dt = $p['ts'] - $time;
            if ($dt <= 0 && $dt >= -self::ROUTE_TIME_WINDOW_S) {
                $before = $i;
            }
            if ($dt >= 0 && $dt <= self::ROUTE_TIME_WINDOW_S && $after === null) {
                $after = $i;
            }
        }
        if ($before === null || $after === null) {
            return null;
        }

        // Widen until the two points are far enough apart to show a direction.
        while (self::metersBetween($points[$before]['lat'], $points[$before]['lng'], $points[$after]['lat'], $points[$after]['lng']) < self::ROUTE_MIN_SPAN_M) {
            $widened = false;
            if ($before > 0 && $inside($before - 1)) {
                $before--;
                $widened = true;
            }
            if ($after < count($points) - 1 && $inside($after + 1)) {
                $after++;
                $widened = true;
            }
            if (! $widened) {
                return null;
            }
        }

        $near = min(
            self::metersBetween($lat, $lng, $points[$before]['lat'], $points[$before]['lng']),
            self::metersBetween($lat, $lng, $points[$after]['lat'], $points[$after]['lng']),
        );
        if ($near > self::ROUTE_TIME_MAX_OFFSET_M) {
            return null;
        }

        return self::bearing($points[$before]['lat'], $points[$before]['lng'], $points[$after]['lat'], $points[$after]['lng']);
    }

    /** The direction of the route piece nearest to the point, unless the route passed there another way too. */
    private function headingByShape(array $points, float $lat, float $lng): ?float
    {
        $points   = self::smoothRoute($points);
        $best     = null;
        $bestDist = INF;
        $nearby   = [];

        for ($i = 0; $i < count($points) - 1; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];

            $offset = self::metersToSegment($lat, $lng, $a, $b);
            if ($offset > self::ROUTE_MAX_OFFSET_M) {
                continue;
            }

            $direction = self::bearing($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            $nearby[]  = $direction;
            if ($offset < $bestDist) {
                $bestDist = $offset;
                $best     = $direction;
            }
        }

        if ($best === null) {
            return null;
        }

        foreach ($nearby as $direction) {
            if (self::angleBetween($direction, $best) > self::ROUTE_AMBIGUOUS_ANGLE) {
                return null;
            }
        }

        return $best;
    }

    /** Keeps only dots at least ROUTE_STEP_M from the last kept one (and the final dot). */
    private static function smoothRoute(array $points): array
    {
        $kept = [$points[0]];
        foreach ($points as $p) {
            $last = $kept[count($kept) - 1];
            if (self::metersBetween($last['lat'], $last['lng'], $p['lat'], $p['lng']) >= self::ROUTE_STEP_M) {
                $kept[] = $p;
            }
        }

        return $kept;
    }

    /** @return array<int, array{lat: float, lng: float, ts: int|null}> */
    private static function normalizeRoute(array $routePoints): array
    {
        $points = [];
        foreach ($routePoints as $p) {
            if (! is_array($p) || ! isset($p['lat'], $p['lng'])) {
                continue;
            }
            $ts = null;
            if (! empty($p['ts'])) {
                try {
                    $ts = Carbon::parse($p['ts'])->getTimestamp();
                } catch (\Throwable) {
                    $ts = null;
                }
            }
            $points[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'ts' => $ts];
        }

        return $points;
    }

    /** Distance (m) from a point to a route piece, on a flat local map (fine at these short distances). */
    private static function metersToSegment(float $lat, float $lng, array $a, array $b): float
    {
        $mPerLat = 110540;
        $mPerLng = 111320 * cos(deg2rad($lat));

        $ax = ($a['lng'] - $lng) * $mPerLng;
        $ay = ($a['lat'] - $lat) * $mPerLat;
        $bx = ($b['lng'] - $lng) * $mPerLng;
        $by = ($b['lat'] - $lat) * $mPerLat;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSq = $dx * $dx + $dy * $dy;
        $t = $lengthSq > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $lengthSq)) : 0;

        return hypot($ax + $t * $dx, $ay + $t * $dy);
    }

    /**
     * The point reached by going $meters from (lat, lng) in direction $bearing
     * (degrees from north), on a sphere.
     *
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    public static function destinationPoint(float $lat, float $lng, float $bearing, float $meters): array
    {
        $angular = $meters / self::EARTH_RADIUS_M;
        $theta   = deg2rad($bearing);
        $phi1    = deg2rad($lat);
        $lambda1 = deg2rad($lng);

        $phi2 = asin(sin($phi1) * cos($angular) + cos($phi1) * sin($angular) * cos($theta));
        $lambda2 = $lambda1 + atan2(
            sin($theta) * sin($angular) * cos($phi1),
            cos($angular) - sin($phi1) * sin($phi2),
        );

        return [rad2deg($phi2), rad2deg($lambda2)];
    }

    /** Compass bearing from point 1 to point 2, degrees from north (0–360). */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLambda = deg2rad($lng2 - $lng1);

        $y = sin($dLambda) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLambda);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /** Smallest angle between two compass directions, 0–180°. */
    public static function angleBetween(float $a, float $b): float
    {
        $d = fmod(abs($a - $b), 360);

        return $d > 180 ? 360 - $d : $d;
    }

    /** Great-circle (haversine) distance in meters. */
    public static function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($a)));
    }
}

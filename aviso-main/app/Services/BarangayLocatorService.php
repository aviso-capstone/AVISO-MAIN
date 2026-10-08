<?php

namespace App\Services;

use RuntimeException;

/**
 * Resolves a GPS point to the Zamboanga City barangay it falls in, using the
 * PSA/NAMRIA barangay boundaries (PSGC 2023) stored as GeoJSON in
 * database/data/zamboanga-city-barangays.geojson.
 *
 * Plain PHP point-in-polygon — no PostGIS needed, so it behaves the same on
 * PostgreSQL and on the SQLite test database.
 */
class BarangayLocatorService
{
    /** Label stored in `area` when a point is not inside any barangay. */
    public const OUTSIDE_CITY = 'Outside Zamboanga City';

    /**
     * A point just past a boundary (coastal road, GPS drift, a sliver between
     * two shapes) snaps to the nearest barangay if it is at most this far away.
     */
    private const SNAP_METERS = 50;

    /** ~SNAP_METERS expressed in degrees, used to widen bounding boxes. */
    private const SNAP_DEGREES = 0.0005;

    private const METERS_PER_DEG_LAT = 110_574;

    private const METERS_PER_DEG_LNG_AT_EQUATOR = 111_320;

    /**
     * Parsed once per PHP process and shared by every instance.
     *
     * @var array<int, array{code: string, name: string, bbox: array{float, float, float, float}, polygons: array<int, array<int, array<int, array{float, float}>>>}>|null
     */
    private static ?array $barangays = null;

    /**
     * The barangay containing the point, the nearest one within SNAP_METERS,
     * or null when the point is outside Zamboanga City.
     *
     * @return array{code: string, name: string}|null
     */
    public function locate(float $lat, float $lng): ?array
    {
        $candidates = [];

        foreach ($this->barangays() as $barangay) {
            [$minLng, $minLat, $maxLng, $maxLat] = $barangay['bbox'];

            if ($lng < $minLng - self::SNAP_DEGREES || $lng > $maxLng + self::SNAP_DEGREES
                || $lat < $minLat - self::SNAP_DEGREES || $lat > $maxLat + self::SNAP_DEGREES) {
                continue;
            }

            foreach ($barangay['polygons'] as $rings) {
                if ($this->insidePolygon($lng, $lat, $rings)) {
                    return ['code' => $barangay['code'], 'name' => $barangay['name']];
                }
            }

            $candidates[] = $barangay;
        }

        return $this->nearestWithinSnap($lat, $lng, $candidates);
    }

    /**
     * Columns to store on a record: the PSGC barangay code and the readable
     * area name (or OUTSIDE_CITY).
     *
     * @return array{barangay_code: string|null, area: string}
     */
    public function attributesFor(float $lat, float $lng): array
    {
        $barangay = $this->locate($lat, $lng);

        return [
            'barangay_code' => $barangay['code'] ?? null,
            'area' => $barangay['name'] ?? self::OUTSIDE_CITY,
        ];
    }

    /**
     * Every barangay as {code, name}, alphabetical — for filter dropdowns.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function all(): array
    {
        return collect($this->barangays())
            ->map(fn (array $b) => ['code' => $b['code'], 'name' => $b['name']])
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string, bbox: array{float, float, float, float}, polygons: array<int, array<int, array<int, array{float, float}>>>}>
     */
    private function barangays(): array
    {
        if (self::$barangays !== null) {
            return self::$barangays;
        }

        $path = database_path('data/zamboanga-city-barangays.geojson');
        $json = json_decode((string) @file_get_contents($path), true);

        if (! is_array($json) || empty($json['features'])) {
            throw new RuntimeException("Barangay boundaries missing or unreadable at {$path}");
        }

        $barangays = [];

        foreach ($json['features'] as $feature) {
            $geometry = $feature['geometry'];
            $polygons = $geometry['type'] === 'MultiPolygon'
                ? $geometry['coordinates']
                : [$geometry['coordinates']];

            $minLng = $minLat = INF;
            $maxLng = $maxLat = -INF;

            // The outer ring (index 0) of each polygon bounds it; holes sit inside.
            foreach ($polygons as $rings) {
                foreach ($rings[0] as [$lng, $lat]) {
                    $minLng = min($minLng, $lng);
                    $maxLng = max($maxLng, $lng);
                    $minLat = min($minLat, $lat);
                    $maxLat = max($maxLat, $lat);
                }
            }

            $barangays[] = [
                'code' => $feature['properties']['psgc_code'],
                'name' => $feature['properties']['name'],
                'bbox' => [$minLng, $minLat, $maxLng, $maxLat],
                'polygons' => $polygons,
            ];
        }

        return self::$barangays = $barangays;
    }

    /**
     * Even-odd ray casting: inside the outer ring and not inside any hole.
     *
     * @param  array<int, array<int, array{float, float}>>  $rings
     */
    private function insidePolygon(float $x, float $y, array $rings): bool
    {
        if (! $this->insideRing($x, $y, $rings[0])) {
            return false;
        }

        for ($i = 1, $n = count($rings); $i < $n; $i++) {
            if ($this->insideRing($x, $y, $rings[$i])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array{float, float}> $ring */
    private function insideRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @param  array<int, array{code: string, name: string, bbox: array{float, float, float, float}, polygons: array<int, array<int, array<int, array{float, float}>>>}>  $candidates
     * @return array{code: string, name: string}|null
     */
    private function nearestWithinSnap(float $lat, float $lng, array $candidates): ?array
    {
        // Local flat projection around the point: accurate to well under a
        // metre at this scale, and far cheaper than haversine per segment.
        $mLng = self::METERS_PER_DEG_LNG_AT_EQUATOR * cos(deg2rad($lat));
        $mLat = self::METERS_PER_DEG_LAT;

        $best = null;
        $bestDist = self::SNAP_METERS;

        foreach ($candidates as $barangay) {
            foreach ($barangay['polygons'] as $rings) {
                foreach ($rings as $ring) {
                    for ($i = 1, $n = count($ring); $i < $n; $i++) {
                        $dist = $this->pointToSegmentMeters(
                            ($ring[$i - 1][0] - $lng) * $mLng, ($ring[$i - 1][1] - $lat) * $mLat,
                            ($ring[$i][0] - $lng) * $mLng, ($ring[$i][1] - $lat) * $mLat,
                        );

                        if ($dist <= $bestDist) {
                            $bestDist = $dist;
                            $best = ['code' => $barangay['code'], 'name' => $barangay['name']];
                        }
                    }
                }
            }
        }

        return $best;
    }

    /** Distance from the origin to segment (x1,y1)-(x2,y2), all in metres. */
    private function pointToSegmentMeters(float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lengthSq = $dx * $dx + $dy * $dy;

        $t = $lengthSq > 0 ? max(0.0, min(1.0, -($x1 * $dx + $y1 * $dy) / $lengthSq)) : 0.0;

        return hypot($x1 + $t * $dx, $y1 + $t * $dy);
    }
}

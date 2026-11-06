<?php

namespace Tests\Unit;

use App\Models\HazardLog;
use App\Services\BarangayLocatorService;
use App\Services\HazardPositionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Item 1: hazard pins moved from the rider's GPS point to the hazard
 * (forward by the camera distance, in the direction of travel).
 * DB-less: the production migrations use Postgres-only SQL.
 */
class HazardPositionTest extends TestCase
{
    private const LAT = 6.9450000;
    private const LNG = 122.0950000;

    private HazardPositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HazardPositionService(new BarangayLocatorService);
    }

    private function hazard(array $attributes = []): HazardLog
    {
        $log = new HazardLog();
        $log->forceFill(array_merge([
            'type'        => HazardLog::TYPE_POTHOLE,
            'latitude'    => self::LAT,
            'longitude'   => self::LNG,
            'distance'    => 6,
            'heading'     => null,
            'detected_at' => Carbon::parse('2026-11-06 08:00:00'),
        ], $attributes));

        return $log;
    }

    /** Route dots going in $bearing, one every $step m, with times 3 s apart around the detection. */
    private function route(float $bearing, int $count = 12, float $step = 10, bool $withTimes = true): array
    {
        [$lat, $lng] = HazardPositionService::destinationPoint(self::LAT, self::LNG, $bearing + 180, $step * ($count / 2));
        $start  = Carbon::parse('2026-11-06 08:00:00')->subSeconds(3 * intdiv($count, 2));
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $point = ['lat' => $lat, 'lng' => $lng];
            if ($withTimes) {
                $point['ts'] = $start->copy()->addSeconds(3 * $i)->toISOString();
            }
            $points[] = $point;
            [$lat, $lng] = HazardPositionService::destinationPoint($lat, $lng, $bearing, $step);
        }

        return $points;
    }

    private function movedMeters(array $placement): float
    {
        return HazardPositionService::metersBetween(self::LAT, self::LNG, $placement['latitude'], $placement['longitude']);
    }

    public function test_destination_point_moves_the_exact_distance_and_direction(): void
    {
        [$lat, $lng] = HazardPositionService::destinationPoint(self::LAT, self::LNG, 0, 6);
        $this->assertEqualsWithDelta(6.0, HazardPositionService::metersBetween(self::LAT, self::LNG, $lat, $lng), 0.01);
        $this->assertGreaterThan(self::LAT, $lat);
        $this->assertEqualsWithDelta(self::LNG, $lng, 1e-9);

        [$lat2, $lng2] = HazardPositionService::destinationPoint(self::LAT, self::LNG, 90, 10);
        $this->assertEqualsWithDelta(90.0, HazardPositionService::bearing(self::LAT, self::LNG, $lat2, $lng2), 0.1);
        $this->assertEqualsWithDelta(10.0, HazardPositionService::metersBetween(self::LAT, self::LNG, $lat2, $lng2), 0.01);
    }

    public function test_gps_heading_moves_the_pin_forward_by_the_camera_distance(): void
    {
        $placement = $this->service->placement($this->hazard(['heading' => 0, 'distance' => 6]), null);

        $this->assertSame(HazardPositionService::SOURCE_GPS, $placement['position_source']);
        $this->assertEqualsWithDelta(6.0, $this->movedMeters($placement), 0.05);
        $this->assertGreaterThan(self::LAT, $placement['latitude']);
        // The rider's original point is kept.
        $this->assertEqualsWithDelta(self::LAT, $placement['rider_latitude'], 1e-9);
        $this->assertEqualsWithDelta(self::LNG, $placement['rider_longitude'], 1e-9);
        $this->assertArrayHasKey('barangay_code', $placement);
    }

    public function test_the_move_is_capped_at_30_meters(): void
    {
        $placement = $this->service->placement($this->hazard(['heading' => 90, 'distance' => 51]), null);

        $this->assertEqualsWithDelta(HazardPositionService::MAX_SHIFT_M, $this->movedMeters($placement), 0.05);
    }

    public function test_no_direction_means_the_pin_is_not_moved(): void
    {
        $placement = $this->service->placement($this->hazard(), null);

        $this->assertSame(HazardPositionService::SOURCE_NONE, $placement['position_source']);
        $this->assertEqualsWithDelta(0.0, $this->movedMeters($placement), 0.001);
    }

    public function test_no_camera_distance_means_the_pin_is_not_moved(): void
    {
        $placement = $this->service->placement($this->hazard(['heading' => 0, 'distance' => null]), null);

        $this->assertSame(HazardPositionService::SOURCE_NONE, $placement['position_source']);
        $this->assertEqualsWithDelta(0.0, $this->movedMeters($placement), 0.001);
    }

    public function test_without_gps_heading_it_waits_for_the_end_of_an_active_ride(): void
    {
        $this->assertNull($this->service->placement($this->hazard(), $this->route(0), routeComplete: false));
    }

    public function test_route_times_give_the_direction(): void
    {
        $placement = $this->service->placement($this->hazard(['distance' => 8]), $this->route(45));

        $this->assertSame(HazardPositionService::SOURCE_ROUTE, $placement['position_source']);
        $this->assertEqualsWithDelta(45.0, (float) $placement['travel_heading'], 2.0);
        $this->assertEqualsWithDelta(8.0, $this->movedMeters($placement), 0.05);
    }

    public function test_route_shape_gives_the_direction_without_times(): void
    {
        $placement = $this->service->placement($this->hazard(), $this->route(90, withTimes: false));

        $this->assertSame(HazardPositionService::SOURCE_ROUTE, $placement['position_source']);
        $this->assertEqualsWithDelta(90.0, (float) $placement['travel_heading'], 2.0);
    }

    public function test_a_there_and_back_route_is_unclear_so_the_pin_is_not_moved(): void
    {
        $there = $this->route(0, withTimes: false);
        $route = array_merge($there, array_reverse($there));

        $placement = $this->service->placement($this->hazard(), $route);

        $this->assertSame(HazardPositionService::SOURCE_NONE, $placement['position_source']);
        $this->assertEqualsWithDelta(0.0, $this->movedMeters($placement), 0.001);
    }

    public function test_gps_jitter_while_slow_does_not_hide_the_direction(): void
    {
        // Riding north, with a cluster of ~1 m dots pointing every way at the hazard (slow / stopped).
        $route   = $this->route(0, withTimes: false);
        $jitter  = [];
        foreach ([90, 270, 180, 45, 225, 0] as $bearing) {
            [$lat, $lng] = HazardPositionService::destinationPoint(self::LAT, self::LNG, $bearing, 1.2);
            $jitter[] = ['lat' => $lat, 'lng' => $lng];
        }
        array_splice($route, 6, 0, $jitter);

        $placement = $this->service->placement($this->hazard(), $route);

        $this->assertSame(HazardPositionService::SOURCE_ROUTE, $placement['position_source']);
        // Still north; the jitter may tilt it a few degrees (15° off on a 6 m move is under 2 m sideways).
        $this->assertEqualsWithDelta(0.0, min((float) $placement['travel_heading'], 360 - (float) $placement['travel_heading']), 15.0);
    }

    public function test_a_route_far_from_the_point_gives_no_direction(): void
    {
        [$lat, $lng] = HazardPositionService::destinationPoint(self::LAT, self::LNG, 90, 60);
        $placement = $this->service->placement($this->hazard(['latitude' => $lat, 'longitude' => $lng]), $this->route(0, withTimes: false));

        $this->assertSame(HazardPositionService::SOURCE_NONE, $placement['position_source']);
    }
}

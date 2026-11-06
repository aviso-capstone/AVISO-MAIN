<?php

namespace Tests\Unit;

use App\Models\HazardLog;
use App\Services\HazardGroupService;
use App\Services\HazardPositionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Items 2–4: Times Seen, the Hazard to Hazard Distance that skips saves of the
 * same hazard, and the averaged pin. DB-less (Postgres-only migrations).
 */
class HazardGroupTest extends TestCase
{
    private const LAT = 6.9450000;
    private const LNG = 122.0950000;

    private HazardGroupService $groups;
    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new HazardGroupService;
    }

    /** A save $meters from the base point in direction $bearing. */
    private function save(float $bearing, float $meters, array $attributes = []): HazardLog
    {
        [$lat, $lng] = HazardPositionService::destinationPoint(self::LAT, self::LNG, $bearing, $meters);
        $id  = $this->nextId++;
        $log = new HazardLog();
        $log->forceFill(array_merge([
            'haz_code'       => sprintf('POT-20261106-%04d', $id),
            'type'           => HazardLog::TYPE_POTHOLE,
            'latitude'       => $lat,
            'longitude'      => $lng,
            'travel_heading' => 0,
            'trip_id'        => 100 + $id,
            'status'         => HazardLog::STATUS_ACTIVE,
            'detected_at'    => Carbon::parse('2026-11-06 08:00:00')->addMinutes($id),
        ], $attributes));
        $log->id = $id;

        return $log;
    }

    public function test_three_rides_over_one_pothole_is_one_hazard_seen_three_times(): void
    {
        $saves  = [$this->save(0, 0), $this->save(90, 4), $this->save(200, 3)];
        $groups = $this->groups->group($saves);

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['times_seen']);
        $this->assertSame([1, 2, 3], $groups[0]['member_ids']);
        $this->assertTrue($groups[0]['last_seen_at']->equalTo($saves[2]->detected_at));
    }

    public function test_the_pin_is_the_average_of_the_saves(): void
    {
        $saves  = [$this->save(0, 4), $this->save(180, 4), $this->save(90, 2), $this->save(270, 2)];
        $group  = $this->groups->group($saves)[0];

        $this->assertEqualsWithDelta(
            0.0,
            HazardPositionService::metersBetween(self::LAT, self::LNG, $group['latitude'], $group['longitude']),
            0.05,
        );
    }

    public function test_two_saves_seconds_apart_on_one_ride_are_two_hazards(): void
    {
        $at     = Carbon::parse('2026-11-06 08:00:00');
        $groups = $this->groups->group([
            $this->save(0, 0, ['trip_id' => 7, 'detected_at' => $at]),
            $this->save(0, 4, ['trip_id' => 7, 'detected_at' => $at->copy()->addSeconds(5)]),
        ]);

        $this->assertCount(2, $groups);
    }

    public function test_passing_the_same_spot_again_on_one_ride_is_seen_twice(): void
    {
        $at     = Carbon::parse('2026-11-06 08:00:00');
        $groups = $this->groups->group([
            $this->save(0, 0, ['trip_id' => 7, 'detected_at' => $at]),
            $this->save(0, 3, ['trip_id' => 7, 'detected_at' => $at->copy()->addSeconds(63)]),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['times_seen']);
    }

    public function test_the_new_pass_rule_starts_at_30_seconds(): void
    {
        $at = Carbon::parse('2026-11-06 08:00:00');
        $pair = fn (int $seconds) => $this->groups->group([
            $this->save(0, 0, ['trip_id' => 9, 'detected_at' => $at]),
            $this->save(0, 3, ['trip_id' => 9, 'detected_at' => $at->copy()->addSeconds($seconds)]),
        ]);

        $this->assertCount(2, $pair(29));
        $this->assertCount(1, $pair(30));
    }

    public function test_saves_without_a_ride_each_count_as_one_time(): void
    {
        $groups = $this->groups->group([
            $this->save(0, 0, ['trip_id' => null]),
            $this->save(0, 3, ['trip_id' => null]),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['times_seen']);
    }

    public function test_the_opposite_direction_is_another_hazard(): void
    {
        $groups = $this->groups->group([
            $this->save(0, 0, ['travel_heading' => 10]),
            $this->save(0, 3, ['travel_heading' => 190]),
        ]);

        $this->assertCount(2, $groups);
    }

    public function test_an_unknown_direction_matches_any(): void
    {
        $groups = $this->groups->group([
            $this->save(0, 0, ['travel_heading' => 10]),
            $this->save(0, 3, ['travel_heading' => null, 'heading' => null]),
        ]);

        $this->assertCount(1, $groups);
    }

    public function test_other_types_are_never_the_same_hazard(): void
    {
        $groups = $this->groups->group([
            $this->save(0, 0),
            $this->save(0, 2, ['type' => HazardLog::TYPE_ROAD_BARRIER]),
        ]);

        $this->assertCount(2, $groups);
    }

    public function test_each_type_uses_its_own_radius(): void
    {
        // Pothole radius 10 m: 12 m apart are two potholes.
        $this->assertCount(2, $this->groups->group([$this->save(0, 0), $this->save(0, 12)]));

        // Barrier radius 30 m: 25 m apart is one barrier.
        $barrier = ['type' => HazardLog::TYPE_ROAD_BARRIER];
        $this->assertCount(1, $this->groups->group([$this->save(0, 0, $barrier), $this->save(0, 25, $barrier)]));
    }

    public function test_hazard_to_hazard_distance_skips_saves_of_the_same_hazard(): void
    {
        $first  = $this->save(0, 0);
        $again  = $this->save(0, 2);    // the same pothole, seen again
        $other  = $this->save(90, 48);  // a different pothole
        $groups = $this->groups->group([$first, $again, $other]);

        $nearest = $this->groups->nearestOtherHazard($first, $groups);

        $this->assertSame($other->haz_code, $nearest['code']);
        $this->assertEqualsWithDelta(48.0, $nearest['meters'], 0.5);
    }

    public function test_hazard_to_hazard_distance_is_empty_when_there_is_no_other_hazard(): void
    {
        $first  = $this->save(0, 0);
        $groups = $this->groups->group([$first, $this->save(0, 2)]);

        $this->assertNull($this->groups->nearestOtherHazard($first, $groups));
    }

    public function test_hazard_to_hazard_distance_ignores_other_types(): void
    {
        $pothole = $this->save(0, 0);
        $groups  = $this->groups->group([$pothole, $this->save(0, 20, ['type' => HazardLog::TYPE_ROAD_BARRIER])]);

        $this->assertNull($this->groups->nearestOtherHazard($pothole, $groups));
    }
}

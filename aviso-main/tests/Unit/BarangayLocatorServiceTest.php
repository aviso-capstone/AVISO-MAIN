<?php

namespace Tests\Unit;

use App\Services\BarangayLocatorService;
use Tests\TestCase;

class BarangayLocatorServiceTest extends TestCase
{
    private BarangayLocatorService $locator;

    /** @var array<int, array<string, mixed>> */
    private array $features;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locator = new BarangayLocatorService;
        $this->features = json_decode(
            file_get_contents(database_path('data/zamboanga-city-barangays.geojson')),
            true,
        )['features'];
    }

    public function test_boundary_file_has_all_98_barangays_with_unique_psgc_codes(): void
    {
        $codes = array_column(array_column($this->features, 'properties'), 'psgc_code');

        $this->assertCount(98, $codes);
        $this->assertCount(98, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^09317\d{5}$/', $code);
        }
    }

    public function test_every_barangay_and_every_island_part_is_reachable(): void
    {
        foreach ($this->features as $feature) {
            $geometry = $feature['geometry'];
            $polygons = $geometry['type'] === 'MultiPolygon' ? $geometry['coordinates'] : [$geometry['coordinates']];
            $code = $feature['properties']['psgc_code'];

            // Each separate part (mainland or island) must resolve back to its
            // own barangay somewhere inside it.
            foreach ($polygons as $i => $rings) {
                $this->assertTrue(
                    $this->somePointResolvesTo($rings[0], $code),
                    "No point inside part {$i} of {$feature['properties']['name']} ({$code}) resolves to it",
                );
            }
        }
    }

    public function test_known_city_locations(): void
    {
        $this->assertSame('Pasonanca', $this->locator->locate(6.9400, 122.0700)['name']);
        $this->assertSame('Canelar', $this->locator->locate(6.9105, 122.0730)['name']);
    }

    public function test_points_far_outside_the_city_return_null(): void
    {
        $this->assertNull($this->locator->locate(6.8500, 121.9500)); // Basilan Strait
        $this->assertNull($this->locator->locate(14.5995, 120.9842)); // Manila

        $this->assertSame(
            ['barangay_code' => null, 'area' => BarangayLocatorService::OUTSIDE_CITY],
            $this->locator->attributesFor(14.5995, 120.9842),
        );
    }

    public function test_point_just_offshore_snaps_to_the_nearest_barangay(): void
    {
        // Walk from a point inside Canelar straight south toward the sea in
        // ~2 m steps; the first point no outline contains is just offshore.
        $lat = 6.9105;
        $lng = 122.0730;
        do {
            $lat -= 0.00002;
            $located = $this->locator->locate($lat, $lng);
        } while ($located !== null && $this->strictlyInsideAny($lat, $lng));

        $this->assertNotNull($located, 'A point a few metres past the coast should snap to a barangay');
        $this->assertNull($this->locator->locate($lat - 0.002, $lng), '~220 m offshore is outside the snap distance');
    }

    public function test_all_lists_every_barangay_alphabetically_with_distinct_dulian_names(): void
    {
        $all = $this->locator->all();
        $names = array_column($all, 'name');

        $this->assertCount(98, $all);
        $sorted = $names;
        natsort($sorted);
        $this->assertSame(array_values($sorted), $names);
        $this->assertContains('Dulian (Upper Bunguiao)', $names);
        $this->assertContains('Dulian (Upper Pasonanca)', $names);
    }

    /** @param array<int, array{float, float}> $ring */
    private function somePointResolvesTo(array $ring, string $code): bool
    {
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);
        [$minLng, $maxLng, $minLat, $maxLat] = [min($lngs), max($lngs), min($lats), max($lats)];

        $steps = 24;
        for ($i = 1; $i < $steps; $i++) {
            for ($j = 1; $j < $steps; $j++) {
                $lng = $minLng + ($maxLng - $minLng) * $i / $steps;
                $lat = $minLat + ($maxLat - $minLat) * $j / $steps;

                if ($this->insideRing($lng, $lat, $ring) && ($this->locator->locate($lat, $lng)['code'] ?? null) === $code) {
                    return true;
                }
            }
        }

        return false;
    }

    private function strictlyInsideAny(float $lat, float $lng): bool
    {
        foreach ($this->features as $feature) {
            $geometry = $feature['geometry'];
            $polygons = $geometry['type'] === 'MultiPolygon' ? $geometry['coordinates'] : [$geometry['coordinates']];
            foreach ($polygons as $rings) {
                if ($this->insideRing($lng, $lat, $rings[0])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Independent even-odd test so the assertions don't just mirror the service. */
    private function insideRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}

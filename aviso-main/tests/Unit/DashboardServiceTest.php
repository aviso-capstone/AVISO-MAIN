<?php

namespace Tests\Unit;

use App\Models\HazardLog;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hazard_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status')->default('active');
            $table->float('confidence')->default(70);
            $table->string('area')->nullable();
            $table->string('barangay_code')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hazard(string $detectedAtUtc, string $type = HazardLog::TYPE_POTHOLE, ?string $area = null): void
    {
        DB::table('hazard_logs')->insert([
            'type'          => $type,
            'area'          => $area,
            'barangay_code' => $area ? 'code-' . $area : null,
            'detected_at'   => $detectedAtUtc,
        ]);
    }

    public function test_today_and_the_7_day_chart_use_philippine_days(): void
    {
        // Nov 7, 10:00 AM in Manila = Nov 7, 02:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-07 02:00:00', 'UTC'));

        $this->hazard('2026-11-06 23:30:00'); // Nov 7, 7:30 AM Manila: today
        $this->hazard('2026-11-07 01:00:00'); // Nov 7, 9:00 AM Manila: today
        $this->hazard('2026-11-06 15:00:00'); // Nov 6, 11:00 PM Manila: yesterday

        $service = new DashboardService();
        $this->assertSame(2, $service->getRiderOverview()['today_detections']);

        $trend = $service->getMetrics()['hazardsOverTimeData'];
        $this->assertCount(7, $trend);
        $this->assertSame('Sat', $trend[6]['day']);
        $this->assertSame(2, $trend[6]['potholes'], 'today');
        $this->assertSame(1, $trend[5]['potholes'], 'yesterday');
    }

    public function test_top_barangays_shows_up_to_10_ranked_by_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 02:00:00', 'UTC'));

        for ($i = 1; $i <= 12; $i++) {
            for ($n = 0; $n < $i; $n++) {
                $this->hazard('2026-11-01 00:00:00', HazardLog::TYPE_ROAD_BARRIER, "Barangay {$i}");
            }
        }
        $this->hazard('2026-11-01 00:00:00'); // outside the city: not ranked

        $top = (new DashboardService())->getRiderOverview()['top_barangays'];

        $this->assertCount(10, $top);
        $this->assertSame('Barangay 12', $top[0]['area']);
        $this->assertSame(12, $top[0]['total']);
        $this->assertSame(12, $top[0]['roadBarriers']);
        $this->assertSame('Barangay 3', $top[9]['area']);
    }

    public function test_the_rider_overview_route_needs_a_rider_login(): void
    {
        $this->getJson('/api/rider/dashboard')->assertStatus(401);
    }
}

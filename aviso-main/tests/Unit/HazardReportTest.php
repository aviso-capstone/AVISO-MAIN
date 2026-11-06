<?php

namespace Tests\Unit;

use App\Http\Requests\Rider\StoreHazardReportRequest;
use App\Models\HazardLog;
use App\Models\HazardReport;
use App\Services\HazardLogService;
use App\Services\HazardReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Hazard no longer there" report rules, checked without a database (the
 * migrations use Postgres-only SQL, so the SQLite test database cannot be built).
 */
class HazardReportTest extends TestCase
{
    private function errorsFor(array $data): array
    {
        return Validator::make($data, (new StoreHazardReportRequest())->rules())->errors()->keys();
    }

    public function test_a_photo_is_required(): void
    {
        $this->assertContains('photo', $this->errorsFor(['note' => 'Already patched']));
    }

    public function test_a_photo_with_position_and_note_is_accepted(): void
    {
        $this->assertSame([], $this->errorsFor([
            'photo'     => UploadedFile::fake()->image('spot.jpg', 800, 600)->size(500),
            'latitude'  => 6.9214,
            'longitude' => 122.0790,
            'note'      => 'Already patched',
        ]));
    }

    public function test_position_is_optional_but_must_be_complete(): void
    {
        $photo = UploadedFile::fake()->image('spot.jpg')->size(200);

        $this->assertSame([], $this->errorsFor(['photo' => $photo]));
        $this->assertContains('longitude', $this->errorsFor(['photo' => $photo, 'latitude' => 6.92]));
    }

    public function test_large_photos_and_long_notes_are_rejected(): void
    {
        $errors = $this->errorsFor([
            'photo' => UploadedFile::fake()->image('spot.jpg')->size(3000),
            'note'  => str_repeat('a', 201),
        ]);

        $this->assertContains('photo', $errors);
        $this->assertContains('note', $errors);
    }

    public function test_a_resolved_hazard_cannot_be_reported(): void
    {
        $hazard = new HazardLog(['status' => HazardLog::STATUS_RESOLVED]);
        $service = new HazardReportService(
            $this->createMock(\App\Services\SecureFileUploader::class),
            $this->createMock(HazardLogService::class),
        );

        $this->expectException(ValidationException::class);
        $service->submit(
            $hazard,
            new \App\Models\User(),
            UploadedFile::fake()->image('spot.jpg'),
            null,
            null,
            null,
        );
    }

    public function test_a_reviewed_report_cannot_be_reviewed_again(): void
    {
        $report = new HazardReport(['status' => HazardReport::STATUS_CONFIRMED]);
        $service = new HazardReportService(
            $this->createMock(\App\Services\SecureFileUploader::class),
            $this->createMock(HazardLogService::class),
        );

        $this->expectException(ValidationException::class);
        $service->reject($report, new \App\Models\User());
    }

    public function test_group_radius_covers_every_hazard_type(): void
    {
        foreach (HazardLog::TYPES as $type) {
            $this->assertArrayHasKey($type, HazardLog::GROUP_RADIUS_M);
        }
        // Same values as the mobile app's known-hazard grouping (lib/known-hazards.ts).
        $this->assertSame(10, HazardLog::GROUP_RADIUS_M[HazardLog::TYPE_POTHOLE]);
        $this->assertSame(25, HazardLog::GROUP_RADIUS_M[HazardLog::TYPE_ROAD_EXCAVATION]);
        $this->assertSame(30, HazardLog::GROUP_RADIUS_M[HazardLog::TYPE_ROAD_BARRIER]);
    }

    public function test_distance_is_measured_in_meters(): void
    {
        // About 111 m per 0.001° of latitude.
        $meters = HazardLogService::metersBetween(6.9214, 122.0790, 6.9224, 122.0790);
        $this->assertEqualsWithDelta(111, $meters, 1);
    }
}

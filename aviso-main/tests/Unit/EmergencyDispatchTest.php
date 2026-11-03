<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\DispatchEmergencyAlertRequest;
use App\Http\Requests\Admin\ResolveEmergencyAlertRequest;
use App\Models\EmergencyAlert;
use App\Services\EmergencyAlertService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Dispatch tracking rules, checked without a database (the migrations use
 * Postgres-only SQL, so the in-memory SQLite test database cannot be built).
 */
class EmergencyDispatchTest extends TestCase
{
    /** @param class-string<FormRequest> $requestClass */
    private function errorsFor(string $requestClass, array $data): array
    {
        $request = $requestClass::create('/', 'POST', $data);

        return Validator::make($data, $request->rules())->errors()->keys();
    }

    public function test_dispatch_form_accepts_list_choices(): void
    {
        $this->assertSame([], $this->errorsFor(DispatchEmergencyAlertRequest::class, [
            'responder_agency'     => 'zcdrrmo',
            'eta_minutes'          => 15,
            'responder_first_name' => 'Juan',
            'responder_last_name'  => 'Dela Cruz',
        ]));

        // Unknown ETA and no responder name are allowed.
        $this->assertSame([], $this->errorsFor(DispatchEmergencyAlertRequest::class, [
            'responder_agency' => 'ems',
            'eta_minutes'      => null,
        ]));
    }

    public function test_dispatch_form_rejects_values_outside_the_lists(): void
    {
        $errors = $this->errorsFor(DispatchEmergencyAlertRequest::class, [
            'responder_agency'     => 'made_up',
            'eta_minutes'          => 7,
            'responder_first_name' => '<script>',
        ]);

        $this->assertEqualsCanonicalizing(['responder_agency', 'eta_minutes', 'responder_first_name'], $errors);
    }

    public function test_other_agency_needs_a_typed_name(): void
    {
        $this->assertSame(['responder_agency_other'], $this->errorsFor(DispatchEmergencyAlertRequest::class, [
            'responder_agency' => 'other',
        ]));

        $this->assertSame([], $this->errorsFor(DispatchEmergencyAlertRequest::class, [
            'responder_agency'       => 'other',
            'responder_agency_other' => 'Tetuan Barangay Tanod',
        ]));
    }

    public function test_resolve_needs_an_outcome_and_a_note_for_other(): void
    {
        $this->assertSame(['outcome'], $this->errorsFor(ResolveEmergencyAlertRequest::class, []));
        $this->assertSame(['resolution_note'], $this->errorsFor(ResolveEmergencyAlertRequest::class, ['outcome' => 'other']));
        $this->assertSame([], $this->errorsFor(ResolveEmergencyAlertRequest::class, ['outcome' => 'treated_on_site']));
    }

    public function test_steps_must_happen_in_order(): void
    {
        $service = app(EmergencyAlertService::class);

        // On scene before help was dispatched.
        $pending = new EmergencyAlert(['status' => EmergencyAlert::STATUS_PENDING]);
        $this->assertThrows(fn () => $service->markOnScene($pending), ValidationException::class);

        // A second dispatch (e.g. another admin at the same time).
        $dispatched = new EmergencyAlert(['status' => EmergencyAlert::STATUS_ACKNOWLEDGED]);
        $this->assertThrows(
            fn () => $service->dispatch($dispatched, ['responder_agency' => 'ems'], new \App\Models\User()),
            ValidationException::class,
        );

        // Resolving twice.
        $resolved = new EmergencyAlert(['status' => EmergencyAlert::STATUS_RESOLVED]);
        $this->assertThrows(fn () => $service->resolve($resolved, ['outcome' => 'rider_ok']), ValidationException::class);
    }

    public function test_rider_status_shows_agency_and_eta_but_no_names(): void
    {
        $alert = new EmergencyAlert([
            'status'                 => EmergencyAlert::STATUS_ACKNOWLEDGED,
            'triggered_at'           => now()->subMinutes(5),
            'dispatched_at'          => now(),
            'responder_agency'       => 'other',
            'responder_agency_other' => 'Tetuan Barangay Tanod',
            'responder_first_name'   => 'Juan',
            'responder_last_name'    => 'Dela Cruz',
            'eta_minutes'            => 15,
        ]);

        $status = EmergencyAlertService::riderStatus($alert);

        $this->assertSame('Tetuan Barangay Tanod', $status['agency_label']);
        $this->assertSame(15, $status['eta_minutes']);
        $this->assertArrayNotHasKey('responder_first_name', $status);
        $this->assertArrayNotHasKey('responder_last_name', $status);
        $this->assertArrayNotHasKey('handled_by_name', $status);
    }
}

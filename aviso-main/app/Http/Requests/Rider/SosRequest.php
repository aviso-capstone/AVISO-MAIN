<?php

namespace App\Http\Requests\Rider;

use App\Models\EmergencyAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // When the incident actually happened, as recorded on the device.
            // Optional for backwards compatibility with older app builds; when
            // present it is the idempotency key that stops the app's retry
            // queue from creating a duplicate alert for the same incident.
            'triggered_at' => ['nullable', 'date'],
            // Crash id from the IoT unit, when the SOS started from a device
            // crash. Lets the server merge it with the device's own backup SOS.
            'event_uid'    => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // How the SOS started. Optional for older app builds (stored as null).
            'trigger_type' => ['nullable', Rule::in(EmergencyAlert::TRIGGER_TYPES)],
        ];
    }
}

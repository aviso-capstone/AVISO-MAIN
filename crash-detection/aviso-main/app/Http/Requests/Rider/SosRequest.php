<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}

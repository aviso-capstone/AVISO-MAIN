<?php

namespace App\Http\Requests\Rider;

use App\Models\RiderEvent;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiderEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Id from the IoT unit; the same event may be uploaded more than once.
            'event_uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'event_type' => ['required', Rule::in(RiderEvent::TYPES)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'acceleration_peak' => ['required', 'numeric', 'between:0,100'],
            'vertical_g' => ['nullable', 'numeric', 'between:0,100'],
            'horizontal_g' => ['nullable', 'numeric', 'between:0,100'],
            'gyro_peak_dps' => ['nullable', 'numeric', 'between:0,5000'],
            'tilt_deg' => ['nullable', 'numeric', 'between:0,180'],
            'detected_at' => ['nullable', ...InputRules::appTimestamp()],
        ];
    }
}

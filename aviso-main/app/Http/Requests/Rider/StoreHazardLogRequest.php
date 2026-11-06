<?php

namespace App\Http\Requests\Rider;

use App\Models\HazardLog;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHazardLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'        => ['required', Rule::in(HazardLog::TYPES)],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'confidence'  => ['required', 'numeric', 'between:0,100'],
            'distance'    => ['nullable', 'numeric', 'between:0,100'],
            // GPS fix at detection: accuracy radius (m) and direction of travel (null when stopped).
            'gps_accuracy' => ['nullable', 'numeric', 'between:0,5000'],
            'heading'     => ['nullable', 'numeric', 'between:0,360'],
            'detected_at' => ['nullable', ...InputRules::appTimestamp()],
            // Server id of the ride it was seen on; only used if that trip belongs to this rider.
            'trip_id'     => ['nullable', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Rider;

use App\Models\HazardLog;
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
            'distance'    => ['nullable', 'numeric', 'min:0'],
            'detected_at' => ['nullable', 'date'],
        ];
    }
}

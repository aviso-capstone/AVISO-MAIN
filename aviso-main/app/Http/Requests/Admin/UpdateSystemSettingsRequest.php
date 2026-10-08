<?php

namespace App\Http\Requests\Admin;

use App\Models\HazardLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confidence_threshold'     => ['required', 'integer', 'min:0', 'max:100'],
            'items_per_page'           => ['required', 'integer', 'in:10,15,25,50'],
            'default_sort'             => ['required', 'string', 'in:haz_code,type,area,confidence,distance,detected_at'],
            'emergency_hazard_types'   => ['required', 'array', 'min:1'],
            'emergency_hazard_types.*' => ['string', Rule::in(HazardLog::TYPES)],
        ];
    }
}

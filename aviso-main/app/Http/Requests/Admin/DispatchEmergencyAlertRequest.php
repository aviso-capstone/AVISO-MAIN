<?php

namespace App\Http\Requests\Admin;

use App\Models\EmergencyAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DispatchEmergencyAlertRequest extends FormRequest
{
    /** Letters (any language), spaces, dots, hyphens and apostrophes. */
    private const NAME_PATTERN = "/^[\pL\s.'\-]+$/u";

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'responder_agency'       => ['required', 'string', Rule::in(array_keys(EmergencyAlert::AGENCIES))],
            'responder_agency_other' => [
                'nullable',
                'string',
                'max:60',
                Rule::requiredIf(fn () => $this->input('responder_agency') === EmergencyAlert::AGENCY_OTHER),
            ],
            'eta_minutes'            => ['nullable', 'integer', Rule::in(EmergencyAlert::ETA_OPTIONS)],
            'responder_first_name'   => ['nullable', 'string', 'max:50', 'regex:' . self::NAME_PATTERN],
            'responder_last_name'    => ['nullable', 'string', 'max:50', 'regex:' . self::NAME_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'responder_agency.required'          => 'Choose the agency you called.',
            'responder_agency_other.required'    => 'Type the name of the agency.',
            'responder_first_name.regex'         => 'The first name may only contain letters.',
            'responder_last_name.regex'          => 'The last name may only contain letters.',
        ];
    }
}

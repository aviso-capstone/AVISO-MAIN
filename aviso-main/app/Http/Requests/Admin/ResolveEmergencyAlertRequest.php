<?php

namespace App\Http\Requests\Admin;

use App\Models\EmergencyAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveEmergencyAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'outcome'         => ['required', 'string', Rule::in(array_keys(EmergencyAlert::OUTCOMES))],
            'resolution_note' => [
                'nullable',
                'string',
                'max:300',
                Rule::requiredIf(fn () => $this->input('outcome') === EmergencyAlert::OUTCOME_OTHER),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required'         => 'Choose how the emergency ended.',
            'resolution_note.required' => 'Write what happened when the outcome is "Other".',
        ];
    }
}

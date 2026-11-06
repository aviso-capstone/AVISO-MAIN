<?php

namespace App\Http\Requests\Admin;

use App\Models\EmergencyAlert;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Search and paging of the SOS Alerts page. */
class SosAlertFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', ...InputRules::search()],
            'status'   => ['nullable', Rule::in([
                'all',
                EmergencyAlert::STATUS_PENDING,
                EmergencyAlert::STATUS_ACKNOWLEDGED,
                EmergencyAlert::STATUS_RESOLVED,
            ])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 25, 50])],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}

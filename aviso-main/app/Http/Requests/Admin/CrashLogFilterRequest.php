<?php

namespace App\Http\Requests\Admin;

use App\Models\RiderEvent;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters of the Crash Detection Logs page and its CSV export. */
class CrashLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'     => ['nullable', Rule::in([...RiderEvent::TYPES, 'all'])],
            'search'   => ['nullable', ...InputRules::search()],
            'barangay' => ['nullable', 'string', 'regex:/^(all|\d{10})$/'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('from') ? 'after_or_equal:from' : null]),
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 25, 50])],
            'export'   => ['nullable', Rule::in(['csv'])],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\HazardLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the admin Hazard Logs page and its CSV/PDF exports (same filters, same rows). */
class HazardLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'type'     => ['nullable', Rule::in([...HazardLog::TYPES, 'all'])],
            'barangay' => ['nullable', 'string', 'max:20'],
            'status'   => ['nullable', 'string', 'max:20'],
            'sort'     => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer'],
            // Calendar days (Philippine time); the same day in both = that whole day.
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('from') ? 'after_or_equal:from' : null]),
            'export'   => ['nullable', Rule::in(['csv', 'pdf'])],
        ];
    }
}

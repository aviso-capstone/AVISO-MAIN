<?php

namespace App\Http\Requests\Admin;

use App\Models\HazardLog;
use App\Support\InputRules;
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
            'search'   => ['nullable', ...InputRules::search()],
            'type'     => ['nullable', Rule::in([...HazardLog::TYPES, 'all'])],
            // "all" or a 10-digit PSGC barangay code.
            'barangay' => ['nullable', 'string', 'regex:/^(all|\d{10})$/'],
            'status'   => ['nullable', Rule::in(['all', HazardLog::STATUS_ACTIVE, HazardLog::STATUS_RESOLVED])],
            'sort'     => ['nullable', 'string', 'regex:/^-?(haz_code|type|area|confidence|distance|detected_at|status)$/'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 25, 50])],
            // Calendar days (Philippine time); the same day in both = that whole day.
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('from') ? 'after_or_equal:from' : null]),
            'export'   => ['nullable', Rule::in(['csv', 'pdf'])],
        ];
    }
}

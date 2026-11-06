<?php

namespace App\Http\Requests\Rider;

use App\Models\RiderEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiderEventFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in([...RiderEvent::TYPES, 'all'])],
            'from' => ['nullable', 'date'],
            // "to" is only compared when a "from" date was given.
            'to' => array_filter(['nullable', 'date', $this->filled('from') ? 'after_or_equal:from' : null]),
            'per_page' => ['nullable', 'integer', Rule::in([10, 15, 25, 50])],
        ];
    }
}

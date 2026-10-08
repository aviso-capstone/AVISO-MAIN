<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'relationship'   => ['nullable', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:30'],
        ];
    }
}

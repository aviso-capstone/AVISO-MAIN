<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'string', 'max:255'],
            'relationship'   => ['nullable', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'string', 'max:20'],
        ];
    }
}

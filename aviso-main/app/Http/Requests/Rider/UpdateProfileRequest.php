<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => [
                'sometimes', 'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->ignore(Auth::id()),
            ],
            'street'      => ['nullable', 'string', 'max:255'],
            'barangay_id' => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'     => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id' => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'   => ['nullable', 'string', 'exists:regions,region_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username may only contain letters, numbers, and underscores.',
        ];
    }
}

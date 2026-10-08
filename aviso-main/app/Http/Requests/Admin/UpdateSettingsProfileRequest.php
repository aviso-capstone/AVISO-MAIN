<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'middle_name'    => ['nullable', 'string', 'max:255'],
            'last_name'      => ['required', 'string', 'max:255'],
            'username'       => ['required', 'string', 'max:255', Rule::unique('users')->ignore($userId)],
            'email'          => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'street'         => ['nullable', 'string', 'max:255'],
            'barangay_id'    => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'        => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id'    => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'      => ['nullable', 'string', 'exists:regions,region_id'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        $rules = [
            'first_name'     => ['required', 'string', 'max:255'],
            'middle_name'    => ['nullable', 'string', 'max:255'],
            'last_name'      => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string', 'max:1000'],
            'street'         => ['nullable', 'string', 'max:255'],
            'barangay_id'    => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'        => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id'    => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'      => ['nullable', 'string', 'exists:regions,region_id'],
        ];

        if ($user->role !== 'rider') {
            $rules['username'] = ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)];
            $rules['email']    = ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)];
            $rules['role']     = ['required', Rule::in(['admin', 'rider'])];

            if ($this->filled('password')) {
                $rules['password'] = ['required', 'string', Password::min(8)->mixedCase(), 'confirmed'];
            }
        }

        return $rules;
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        $rules = [
            'first_name'     => ['required', ...InputRules::personName()],
            'middle_name'    => ['nullable', ...InputRules::personName()],
            'last_name'      => ['required', ...InputRules::personName()],
            'contact_number' => ['nullable', ...InputRules::phMobile()],
            'address'        => ['nullable', 'string', 'max:1000'],
            'street'         => ['nullable', ...InputRules::street()],
            'barangay_id'    => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'        => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id'    => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'      => ['nullable', 'string', 'exists:regions,region_id'],
        ];

        // The role is never editable: an admin stays an admin and a rider stays
        // a rider (a rider account is only ever made by signing up in the app).
        if ($user->role !== 'rider') {
            $rules['username'] = ['required', ...InputRules::username(), Rule::unique('users')->ignore($user->id)];
            $rules['email']    = ['required', ...InputRules::email(), Rule::unique('users')->ignore($user->id)];

            if ($this->filled('password')) {
                $rules['password'] = ['required', ...InputRules::password()];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

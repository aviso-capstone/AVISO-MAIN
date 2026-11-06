<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // An unverified sign-up can be repeated, so only verified accounts block a username or email.
        $verified = fn ($q) => $q->whereNotNull('email_verified_at');

        return [
            'first_name'            => ['required', ...InputRules::personName()],
            'middle_name'           => ['nullable', ...InputRules::personName()],
            'last_name'             => ['required', ...InputRules::personName()],
            'username'              => ['required', ...InputRules::username(), Rule::unique('users')->where($verified)],
            'email'                 => ['required', ...InputRules::email(), Rule::unique('users')->where($verified)],
            'contact_number'        => ['required', ...InputRules::phMobile()],
            'password'              => ['required', ...InputRules::password()],
            'password_confirmation' => ['required', 'string'],
            'street'                => ['required', ...InputRules::street()],
            'barangay_id'           => ['required', 'string', 'exists:barangays,code'],
            'city_id'               => ['required', 'string', 'exists:cities,city_id'],
            'province_id'           => ['required', 'string', 'exists:provinces,province_id'],
            'region_id'             => ['required', 'string', 'exists:regions,region_id'],
        ];
    }

    public function messages(): array
    {
        return [
            ...InputRules::messages(),
            'username.unique' => 'This username is already taken. Please choose another.',
            'email.unique'    => 'An account with this email already exists. Please log in instead.',
        ];
    }
}

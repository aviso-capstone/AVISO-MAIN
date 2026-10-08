<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:100'],
            'middle_name'           => ['nullable', 'string', 'max:100'],
            'last_name'             => ['required', 'string', 'max:100'],
            'username'              => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users')->where(
                    fn ($q) => $q->whereNotNull('email_verified_at')
                ),
            ],
            // Allow re-registration only when the email is unverified (e.g. resend OTP flow).
            // Verified accounts cannot be overwritten — they must use a password-reset flow.
            'email'                 => [
                'required',
                'email',
                Rule::unique('users')->where(
                    fn ($q) => $q->whereNotNull('email_verified_at')
                ),
            ],
            'contact_number'        => ['required', 'string', 'regex:/^\+639\d{9}$/'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
            'street'                => ['required', 'string', 'max:255'],
            'barangay_id'           => ['required', 'string', 'exists:barangays,code'],
            'city_id'               => ['required', 'string', 'exists:cities,city_id'],
            'province_id'           => ['required', 'string', 'exists:provinces,province_id'],
            'region_id'             => ['required', 'string', 'exists:regions,region_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.unique'         => 'This username is already taken. Please choose another.',
            'username.regex'          => 'Username may only contain letters, numbers, and underscores.',
            'email.unique'            => 'An account with this email already exists. Please log in instead.',
            'contact_number.regex'    => 'Contact number must be in +639XXXXXXXXX format (e.g. +639171234567).',
        ];
    }
}

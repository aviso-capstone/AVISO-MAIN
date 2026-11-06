<?php

namespace App\Http\Requests\Rider;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** A 6-digit code sent to the rider's email (sign-up or password reset). */
class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Emails are saved trimmed and lowercase (see NormalizesInput), so look them up the same way. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', ...InputRules::email()],
            'otp'   => ['required', 'digits:6'],
        ];
    }
}

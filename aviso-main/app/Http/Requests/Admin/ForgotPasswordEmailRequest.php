<?php

namespace App\Http\Requests\Admin;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Forgot password step 1: the admin's email. */
class ForgotPasswordEmailRequest extends FormRequest
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
        ];
    }
}

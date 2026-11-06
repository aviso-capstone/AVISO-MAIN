<?php

namespace App\Http\Requests\Rider;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Forgot password last step: the token from the code check and the new password. */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reset_token'           => ['required', 'string', 'max:255'],
            'password'              => ['required', ...InputRules::password()],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}

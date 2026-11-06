<?php

namespace App\Http\Requests\Admin;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** Forgot password step 3: the new password (email and nonce come from the signed link). */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password'              => ['required', ...InputRules::password()],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}

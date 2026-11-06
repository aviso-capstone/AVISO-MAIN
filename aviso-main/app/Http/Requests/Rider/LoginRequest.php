<?php

namespace App\Http\Requests\Rider;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Trim the identifier; emails are saved lowercase, usernames keep their case. */
    protected function prepareForValidation(): void
    {
        $id = $this->input('email');
        if (is_string($id)) {
            $id = trim($id);
            $this->merge(['email' => str_contains($id, '@') ? mb_strtolower($id) : $id]);
        }
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'max:' . InputRules::LOGIN_MAX],  // accepts email address or username
            'password' => ['required', 'string', 'max:' . InputRules::PASSWORD_MAX],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:' . InputRules::PASSWORD_MAX, 'current_password'],
            'password'         => ['required', ...InputRules::password()],
        ];
    }
}

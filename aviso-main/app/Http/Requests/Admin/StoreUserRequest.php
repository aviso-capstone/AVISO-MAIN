<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesPersonNames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    use NormalizesPersonNames;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The website only adds admins: riders create their own account in the
     * app. The role is set by UserService::createAdmin, never by the form.
     */
    public function rules(): array
    {
        return [
            'first_name'     => ['required', 'string', 'max:255'],
            'middle_name'    => ['nullable', 'string', 'max:255'],
            'last_name'      => ['required', 'string', 'max:255'],
            'username'       => ['required', 'string', 'max:255', 'unique:users'],
            'email'          => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'password'       => ['required', 'string', Password::min(8)->mixedCase(), 'confirmed'],
        ];
    }
}

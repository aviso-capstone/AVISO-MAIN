<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    use NormalizesInput;

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
            'first_name'     => ['required', ...InputRules::personName()],
            'middle_name'    => ['nullable', ...InputRules::personName()],
            'last_name'      => ['required', ...InputRules::personName()],
            'username'       => ['required', ...InputRules::username(), 'unique:users'],
            'email'          => ['required', ...InputRules::email(), 'unique:users'],
            'contact_number' => ['nullable', ...InputRules::phMobile()],
            'password'       => ['required', ...InputRules::password()],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

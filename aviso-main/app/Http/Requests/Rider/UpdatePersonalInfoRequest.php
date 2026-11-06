<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdatePersonalInfoRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'     => ['sometimes', 'required', ...InputRules::personName()],
            'last_name'      => ['sometimes', 'required', ...InputRules::personName()],
            'email'          => ['sometimes', 'required', ...InputRules::email(), Rule::unique('users', 'email')->ignore(Auth::id())],
            'contact_number' => ['sometimes', 'required', ...InputRules::phMobile()],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

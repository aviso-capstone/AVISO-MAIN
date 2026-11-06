<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsProfileRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name'     => ['required', ...InputRules::personName()],
            'middle_name'    => ['nullable', ...InputRules::personName()],
            'last_name'      => ['required', ...InputRules::personName()],
            'username'       => ['required', ...InputRules::username(), Rule::unique('users')->ignore($userId)],
            'email'          => ['required', ...InputRules::email(), Rule::unique('users')->ignore($userId)],
            'contact_number' => ['nullable', ...InputRules::phMobile()],
            'street'         => ['nullable', ...InputRules::street()],
            'barangay_id'    => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'        => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id'    => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'      => ['nullable', 'string', 'exists:regions,region_id'],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

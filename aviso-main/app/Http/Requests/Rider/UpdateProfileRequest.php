<?php

namespace App\Http\Requests\Rider;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username'    => ['sometimes', 'required', ...InputRules::username(), Rule::unique('users', 'username')->ignore(Auth::id())],
            'street'      => ['nullable', ...InputRules::street()],
            'barangay_id' => ['nullable', 'string', 'exists:barangays,code'],
            'city_id'     => ['nullable', 'string', 'exists:cities,city_id'],
            'province_id' => ['nullable', 'string', 'exists:provinces,province_id'],
            'region_id'   => ['nullable', 'string', 'exists:regions,region_id'],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

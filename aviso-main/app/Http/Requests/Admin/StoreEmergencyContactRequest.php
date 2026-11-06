<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** An admin adds an emergency contact for a rider (the SOS text goes to this number). */
class StoreEmergencyContactRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', ...InputRules::contactName()],
            'relationship'   => ['nullable', ...InputRules::relationship()],
            'contact_number' => ['required', ...InputRules::phMobile()],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

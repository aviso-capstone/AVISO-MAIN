<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

/** A rider adds an emergency contact (the SOS text goes to this number). */
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

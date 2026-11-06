<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Concerns\NormalizesInput;
use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmergencyContactRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'required', ...InputRules::contactName()],
            'relationship'   => ['nullable', ...InputRules::relationship()],
            'contact_number' => ['sometimes', 'required', ...InputRules::phMobile()],
        ];
    }

    public function messages(): array
    {
        return InputRules::messages();
    }
}

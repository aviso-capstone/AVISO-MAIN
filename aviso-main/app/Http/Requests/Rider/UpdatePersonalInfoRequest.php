<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Concerns\NormalizesPersonNames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdatePersonalInfoRequest extends FormRequest
{
    use NormalizesPersonNames;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = Auth::id();
        return [
            'first_name'     => ['sometimes', 'string', 'max:50'],
            'last_name'      => ['sometimes', 'string', 'max:50'],
            'email'          => ['sometimes', 'email', 'max:255', "unique:users,email,{$userId}"],
            'contact_number' => ['sometimes', 'string', 'max:20'],
        ];
    }
}

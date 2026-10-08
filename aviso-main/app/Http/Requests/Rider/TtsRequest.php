<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class TtsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text'      => ['required', 'string', 'max:500'],
            'cache_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/'],
        ];
    }
}

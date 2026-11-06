<?php

namespace App\Http\Requests\Admin;

use App\Support\InputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Search, tab and sort of the Users page. */
class UserFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', ...InputRules::search()],
            'role'   => ['nullable', Rule::in(['rider', 'admin', 'all'])],
            'sort'   => ['nullable', 'string', 'regex:/^-?(first_name|username|email|role|created_at)$/'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}

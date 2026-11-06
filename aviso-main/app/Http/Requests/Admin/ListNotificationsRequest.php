<?php

namespace App\Http\Requests\Admin;

use App\Notifications\AvisoNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters of the admin Notifications page. */
class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return string[] */
    protected function categories(): array
    {
        return AvisoNotification::ADMIN_CATEGORIES;
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::in($this->categories())],
            'unread'   => ['nullable', 'boolean'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{category: ?string, unread: bool, from: ?string, to: ?string} */
    public function filters(): array
    {
        return [
            'category' => $this->validated('category'),
            'unread'   => $this->boolean('unread'),
            'from'     => $this->validated('from'),
            'to'       => $this->validated('to'),
        ];
    }
}

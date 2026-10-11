<?php

namespace App\Http\Requests\Concerns;

use App\Support\PersonName;

/**
 * Saves names in proper capitals no matter how they were typed
 * (see PersonName::normalize), so the app, admin site and SOS texts agree.
 */
trait NormalizesPersonNames
{
    protected function prepareForValidation(): void
    {
        $names = [];

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $names[$field] = PersonName::normalize($this->input($field));
            }
        }

        $this->merge($names);
    }
}

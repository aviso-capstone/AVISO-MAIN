<?php

namespace App\Http\Requests\Concerns;

use App\Models\EmergencyContact;
use App\Support\PersonName;

/**
 * Tidies typed input before it is validated, so the same value is always
 * saved the same way (app, admin site and SOS texts agree):
 * - names in proper capitals (see PersonName::normalize);
 * - contact names with single spaces;
 * - emails trimmed and lowercase;
 * - Philippine mobile numbers as +639XXXXXXXXX (09…, 9… and +63… all work).
 *   A number that can't be read is left as typed, so validation names it.
 */
trait NormalizesInput
{
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = PersonName::normalize($this->input($field));
            }
        }

        foreach (['name', 'relationship'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(preg_replace('/\s+/u', ' ', $this->input($field)) ?? '');
            }
        }

        if (is_string($this->input('email'))) {
            $clean['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('contact_number')) && trim($this->input('contact_number')) !== '') {
            $clean['contact_number'] = EmergencyContact::normalizeToE164Ph($this->input('contact_number'))
                ?? trim($this->input('contact_number'));
        }

        $this->merge($clean);
    }
}

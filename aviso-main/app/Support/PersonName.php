<?php

namespace App\Support;

class PersonName
{
    /**
     * Tidies a typed name: trims it, collapses extra spaces and, when it was
     * typed all lowercase or ALL CAPS, turns it into Title Case
     * ("juan dela cruz" → "Juan Dela Cruz"). Mixed capitals are kept as typed,
     * so names like "McDonald" or "dela Cruz" are never "corrected" wrongly.
     */
    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        $isAllLower = mb_strtolower($name, 'UTF-8') === $name;
        $isAllUpper = mb_strtoupper($name, 'UTF-8') === $name;

        return $isAllLower || $isAllUpper
            ? mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
            : $name;
    }
}

<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * One rule per kind of input, shared by every Form Request (admin website and
 * rider app), so the same thing is never limited differently in two places.
 * Each method returns the rules without required / nullable / sometimes; the
 * request adds that in front.
 *
 * The apps use the same limits (maxLength, allowed characters).
 */
final class InputRules
{
    /** Letters (any language), spaces, period, apostrophe, hyphen. */
    public const NAME_PATTERN = "/^[\pL\s.'\-]+$/u";

    /** Contact names picked from the phone book may hold numbers or brackets ("Kuya Ben (Work)"). */
    public const CONTACT_NAME_PATTERN = "/^[\pL\pN\s.'\-()]+$/u";

    public const USERNAME_PATTERN = '/^[A-Za-z0-9_]+$/';

    /** Philippine mobile, after NormalizesInput turned it into +639XXXXXXXXX. */
    public const PH_MOBILE_PATTERN = '/^\+639\d{9}$/';

    public const RELATIONSHIP_PATTERN = "/^[\pL\s'\-]+$/u";

    public const NAME_MAX         = 50;
    public const USERNAME_MIN     = 3;
    public const USERNAME_MAX     = 30;
    public const EMAIL_MAX        = 100;
    public const RELATIONSHIP_MAX = 30;
    public const STREET_MAX       = 150;
    public const SEARCH_MAX       = 100;
    public const PASSWORD_MIN     = 8;
    public const PASSWORD_MAX     = 64;
    public const LOGIN_MAX        = 100;

    /** Times sent by the app: a little clock drift ahead, offline saves up to 30 days old. */
    public const APP_TIME_AHEAD  = '+5 minutes';
    public const APP_TIME_BEHIND = '-30 days';

    /** First, middle or last name of a person with an account. */
    public static function personName(): array
    {
        return ['string', 'max:' . self::NAME_MAX, 'regex:' . self::NAME_PATTERN];
    }

    /** An emergency contact's name. */
    public static function contactName(): array
    {
        return ['string', 'min:2', 'max:' . self::NAME_MAX, 'regex:' . self::CONTACT_NAME_PATTERN];
    }

    public static function username(): array
    {
        return ['string', 'min:' . self::USERNAME_MIN, 'max:' . self::USERNAME_MAX, 'regex:' . self::USERNAME_PATTERN];
    }

    public static function email(): array
    {
        return ['string', 'email', 'max:' . self::EMAIL_MAX];
    }

    public static function phMobile(): array
    {
        return ['string', 'regex:' . self::PH_MOBILE_PATTERN];
    }

    public static function relationship(): array
    {
        return ['string', 'max:' . self::RELATIONSHIP_MAX, 'regex:' . self::RELATIONSHIP_PATTERN];
    }

    public static function street(): array
    {
        return ['string', 'max:' . self::STREET_MAX];
    }

    public static function search(): array
    {
        return ['string', 'max:' . self::SEARCH_MAX];
    }

    /** A new password: 8–64 characters with an uppercase and a lowercase letter. */
    public static function password(): array
    {
        return ['string', 'confirmed', Password::min(self::PASSWORD_MIN)->max(self::PASSWORD_MAX)->mixedCase()];
    }

    /** When something happened on the phone (hazard, ride event, SOS). */
    public static function appTimestamp(): array
    {
        return ['date', 'before_or_equal:' . self::APP_TIME_AHEAD, 'after_or_equal:' . self::APP_TIME_BEHIND];
    }

    /** Messages shown for the patterns above (attribute names come from Laravel). */
    public static function messages(): array
    {
        return [
            'first_name.regex'     => 'The first name may only contain letters, spaces, periods, apostrophes and hyphens.',
            'middle_name.regex'    => 'The middle name may only contain letters, spaces, periods, apostrophes and hyphens.',
            'last_name.regex'      => 'The last name may only contain letters, spaces, periods, apostrophes and hyphens.',
            'name.regex'           => 'The name may only contain letters, numbers, spaces, periods, apostrophes, hyphens and brackets.',
            'username.regex'       => 'The username may only contain letters, numbers and underscores.',
            'contact_number.regex' => 'Enter a Philippine mobile number, e.g. 09171234567 or +639171234567.',
            'relationship.regex'   => 'The relationship may only contain letters, spaces, apostrophes and hyphens.',
            'detected_at.before_or_equal'  => 'The time cannot be in the future.',
            'detected_at.after_or_equal'   => 'The time is too old (more than 30 days ago).',
            'triggered_at.before_or_equal' => 'The time cannot be in the future.',
            'triggered_at.after_or_equal'  => 'The time is too old (more than 30 days ago).',
        ];
    }
}

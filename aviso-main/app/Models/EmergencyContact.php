<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'relationship',
        'contact_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalize a stored PH mobile number to the +639XXXXXXXXX format
     * required by the SMS gateway. Returns null when the shape is
     * unrecognizable so callers can skip (and log) instead of sending
     * to a malformed recipient.
     */
    public static function normalizeToE164Ph(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+63' . substr($digits, 1);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63' . $digits;
        }

        return null;
    }
}
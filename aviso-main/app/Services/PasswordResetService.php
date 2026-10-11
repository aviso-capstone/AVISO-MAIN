<?php

namespace App\Services;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    /** Wrong guesses allowed per OTP before it is thrown away. */
    private const MAX_OTP_ATTEMPTS = 5;

    private const RESET_LINK_MINUTES = 15;

    public function __construct(private readonly UserService $userService) {}

    /**
     * Generate a 6-digit OTP, store a hashed copy, and send it via Brevo.
     *
     * Any previous OTPs for this email are deleted first so only one is
     * ever active at a time.
     *
     * Silently does nothing for emails that aren't admin accounts, so the
     * response never reveals which emails belong to an admin.
     */
    public function sendOtp(string $email): void
    {
        $user = User::where('email', $email)->where('role', 'admin')->first();

        if (!$user) {
            return;
        }

        // Clear any old OTPs (and their wrong-guess count) for this email
        PasswordResetOtp::where('email', $email)->delete();
        RateLimiter::clear($this->attemptsKey($email));

        // Generate a cryptographically random 6-digit code
        $otp = (string) random_int(100000, 999999);

        // Store hashed version — plain text only goes to the email
        PasswordResetOtp::create([
            'email'      => $email,
            'otp'        => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send via Brevo SMTP
        Mail::to($email)->send(new PasswordResetOtpMail($otp, $email, $user->first_name ?: 'Admin'));
    }

    /**
     * Verify the submitted OTP against the stored hash.
     *
     * On success, the OTP record is deleted (single-use) and a signed
     * short-lived, single-use URL is returned for use in Step 3.
     *
     * After MAX_OTP_ATTEMPTS wrong guesses the OTP is destroyed, no matter
     * how many IP addresses the guesses came from.
     *
     * @return string Signed reset URL to pass to resetPassword()
     * @throws ValidationException on wrong or expired OTP
     */
    public function verifyOtp(string $email, string $otp): string
    {
        $record = PasswordResetOtp::where('email', $email)
            ->latest()
            ->first();

        if (!$record) {
            throw ValidationException::withMessages([
                'otp' => 'No OTP was found for this email. Please request a new one.',
            ]);
        }

        if ($record->isExpired()) {
            $record->delete();
            throw ValidationException::withMessages([
                'otp' => 'This OTP has expired. Please request a new one.',
            ]);
        }

        if (!Hash::check($otp, $record->otp)) {
            RateLimiter::hit($this->attemptsKey($email), 600);

            if (RateLimiter::tooManyAttempts($this->attemptsKey($email), self::MAX_OTP_ATTEMPTS)) {
                $record->delete();
                RateLimiter::clear($this->attemptsKey($email));

                throw ValidationException::withMessages([
                    'otp' => 'Too many incorrect attempts. Please request a new OTP.',
                ]);
            }

            throw ValidationException::withMessages([
                'otp' => 'The OTP you entered is incorrect.',
            ]);
        }

        // Consume the OTP — it can never be used again
        $record->delete();
        RateLimiter::clear($this->attemptsKey($email));

        // The nonce makes the reset link single-use; the email inside the
        // signed URL is the only email Step 3 will ever act on.
        $nonce = Str::random(40);
        Cache::put($this->nonceKey($email), $nonce, now()->addMinutes(self::RESET_LINK_MINUTES));

        return URL::temporarySignedRoute(
            'password.otp.token',
            now()->addMinutes(self::RESET_LINK_MINUTES),
            ['email' => $email, 'nonce' => $nonce],
        );
    }

    /**
     * Reset the password. $email and $nonce must come from the signed URL
     * (already checked by the controller), never from the request body.
     *
     * @throws ValidationException if the link was already used or the account is gone
     */
    public function resetPassword(string $email, string $nonce, string $password): void
    {
        $stored = Cache::pull($this->nonceKey($email));

        if (!is_string($stored) || !hash_equals($stored, $nonce)) {
            throw ValidationException::withMessages([
                'token' => 'Your reset session has expired. Please start over.',
            ]);
        }

        $user = User::where('email', $email)->where('role', 'admin')->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'token' => 'Your reset session has expired. Please start over.',
            ]);
        }

        // Also signs the account out everywhere — whoever caused the reset
        // shouldn't keep an open session.
        $this->userService->updatePassword($user, $password);
    }

    private function attemptsKey(string $email): string
    {
        return 'password-reset-otp:' . Str::lower($email);
    }

    private function nonceKey(string $email): string
    {
        return 'password-reset-nonce:' . Str::lower($email);
    }
}

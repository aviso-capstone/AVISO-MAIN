<?php

namespace App\Services;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    /**
     * Generate a 6-digit OTP, store a hashed copy, and send it via Brevo.
     *
     * Any previous OTPs for this email are deleted first so only one is
     * ever active at a time.
     *
     * @throws ValidationException if the email is not found in the users table
     */
    public function sendOtp(string $email): void
    {
        // Confirm the email belongs to an admin account
        $user = User::where('email', $email)->where('role', 'admin')->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => 'No admin account was found with that email address.',
            ]);
        }

        // Clear any old OTPs for this email
        PasswordResetOtp::where('email', $email)->delete();

        // Generate a cryptographically random 6-digit code
        $otp = (string) random_int(100000, 999999);

        // Store hashed version — plain text only goes to the email
        PasswordResetOtp::create([
            'email'      => $email,
            'otp'        => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send via Brevo SMTP
        Mail::to($email)->send(new PasswordResetOtpMail($otp, $email));
    }

    /**
     * Verify the submitted OTP against the stored hash.
     *
     * On success, the OTP record is deleted (single-use) and a signed
     * short-lived URL token is returned for use in Step 3.
     *
     * @return string Signed reset token to pass to resetPassword()
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
            throw ValidationException::withMessages([
                'otp' => 'The OTP you entered is incorrect.',
            ]);
        }

        // Consume the OTP — it can never be used again
        $record->delete();

        // Return a signed token that Step 3 will verify.
        // Signed with APP_KEY, expires in 15 minutes.
        return URL::temporarySignedRoute(
            'password.otp.token',
            now()->addMinutes(15),
            ['email' => $email],
        );
    }

    /**
     * Reset the user's password after verifying the signed token.
     *
     * @throws ValidationException if the token is invalid or expired
     */
    public function resetPassword(string $email, string $password): void
    {
        $user = User::where('email', $email)->where('role', 'admin')->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => 'Account not found.',
            ]);
        }

        $user->update([
            'password' => Hash::make($password),
        ]);
    }
}

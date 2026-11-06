<?php

namespace App\Services;

use App\Mail\RiderPasswordResetMail;
use App\Mail\RiderVerificationMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Notifications\AdminNotifications;
use App\Notifications\RiderNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class RiderAuthService
{
    /** Wrong guesses allowed per code before it is thrown away (same as the admin reset). */
    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(private NotificationService $notifications)
    {
        //
    }

    public function attemptLogin(string $identifier, string $password): ?array
    {
        $user = User::where(function ($q) use ($identifier) {
                $q->where('email', $identifier)->orWhere('username', $identifier);
            })
            ->where('role', 'rider')
            ->whereNotNull('email_verified_at')
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        // Told only after the right password, so the message can't be used to probe accounts.
        if ($user->isDeactivated()) {
            throw new \DomainException(User::DEACTIVATED_MESSAGE);
        }

        $token = $user->createToken('rider-app')->plainTextToken;

        return [
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'first_name'     => $user->first_name,
                'last_name'      => $user->last_name,
                'email'          => $user->email,
                'contact_number' => $user->contact_number,
                'username'       => $user->username,
                'avatar_url'     => $user->avatar_url,
            ],
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function register(array $data): void
    {
        $user = User::updateOrCreate(
            ['email' => $data['email']],
            [
                'first_name'        => $data['first_name'],
                'middle_name'       => $data['middle_name'] ?? null,
                'last_name'         => $data['last_name'],
                'username'          => $data['username'],
                'contact_number'    => $data['contact_number'],
                'street'            => $data['street'] ?? null,
                'barangay_id'       => $data['barangay_id'] ?? null,
                'city_id'           => $data['city_id'] ?? null,
                'province_id'       => $data['province_id'] ?? null,
                'region_id'         => $data['region_id'] ?? null,
                'password'          => Hash::make($data['password']),
                'role'              => 'rider',
                'email_verified_at' => null,
            ]
        );

        PasswordResetOtp::where('email', $data['email'])->delete();
        RateLimiter::clear($this->otpAttemptsKey($data['email']));

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $data['email'],
            'otp'        => Hash::make($otp), // only the email has the real code
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($data['email'])->send(new RiderVerificationMail($otp, $data['first_name']));
    }

    public function verifyOtp(string $email, string $otp): ?array
    {
        $record = $this->checkOtp($email, $otp);

        if (!$record) {
            return null;
        }

        $user = User::where('email', $email)->where('role', 'rider')->first();

        if (!$user) {
            return null;
        }

        if ($user->isDeactivated()) {
            throw new \DomainException(User::DEACTIVATED_MESSAGE);
        }

        $isNewRider = $user->email_verified_at === null;
        $user->update(['email_verified_at' => now()]);
        $record->delete();
        RateLimiter::clear($this->otpAttemptsKey($email));

        // A new rider counts once their email is verified.
        if ($isNewRider) {
            $this->notifications->notifyAdmins(AdminNotifications::riderRegistered($user));
        }

        $token = $user->createToken('rider-app')->plainTextToken;

        return [
            'token' => $token,
            'user'  => [
                'id'             => $user->id,
                'first_name'     => $user->first_name,
                'last_name'      => $user->last_name,
                'email'          => $user->email,
                'contact_number' => $user->contact_number,
                'username'       => $user->username,
                'avatar_url'     => $user->avatar_url,
            ],
        ];
    }

    public function resendOtp(string $email): bool
    {
        $user = User::where('email', $email)
            ->where('role', 'rider')
            ->whereNull('email_verified_at')
            ->first();

        if (!$user) {
            return false;
        }

        PasswordResetOtp::where('email', $email)->delete();
        RateLimiter::clear($this->otpAttemptsKey($email));

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $email,
            'otp'        => Hash::make($otp), // only the email has the real code
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($email)->send(new RiderVerificationMail($otp, $user->first_name));

        return true;
    }

    public function sendForgotPasswordOtp(string $email): bool
    {
        $user = User::where('email', $email)
            ->where('role', 'rider')
            ->whereNotNull('email_verified_at')
            ->first();

        if (!$user) {
            return false;
        }

        PasswordResetOtp::where('email', $email)->delete();
        RateLimiter::clear($this->otpAttemptsKey($email));

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $email,
            'otp'        => Hash::make($otp), // only the email has the real code
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($email)->send(new RiderPasswordResetMail($otp, $user->first_name));

        return true;
    }

    public function verifyForgotPasswordOtp(string $email, string $otp): ?string
    {
        $record = $this->checkOtp($email, $otp);

        if (!$record) {
            return null;
        }

        $record->delete();
        RateLimiter::clear($this->otpAttemptsKey($email));

        return encrypt([
            'email' => $email,
            'exp'   => now()->addMinutes(15)->timestamp,
        ]);
    }

    public function resetPassword(string $resetToken, string $password): bool
    {
        try {
            $data = decrypt($resetToken);
        } catch (\Exception) {
            return false;
        }

        if (now()->timestamp > $data['exp']) {
            return false;
        }

        $user = User::where('email', $data['email'])
            ->where('role', 'rider')
            ->first();

        if (!$user) {
            return false;
        }

        $user->update(['password' => Hash::make($password)]);
        $this->notifications->notifyUser($user, RiderNotifications::passwordReset());

        return true;
    }

    /**
     * The rider's current code if $otp matches it and it has not expired.
     * Every wrong guess is counted; after MAX_OTP_ATTEMPTS the code is deleted,
     * so a 6-digit code cannot be guessed by trying many numbers.
     *
     * @throws ValidationException when the code was just thrown away
     */
    private function checkOtp(string $email, string $otp): ?PasswordResetOtp
    {
        $record = PasswordResetOtp::where('email', $email)->latest()->first();

        if (!$record || $record->isExpired()) {
            return null;
        }

        if ($this->otpMatches((string) $record->otp, $otp)) {
            return $record;
        }

        $key = $this->otpAttemptsKey($email);
        RateLimiter::hit($key, 600);

        if (RateLimiter::tooManyAttempts($key, self::MAX_OTP_ATTEMPTS)) {
            $record->delete();
            RateLimiter::clear($key);

            throw ValidationException::withMessages([
                'otp' => 'Too many incorrect attempts. Please request a new code.',
            ]);
        }

        return null;
    }

    /**
     * Codes are stored hashed, like the admin reset codes. A code saved in
     * plain text before this change (they last 10 minutes) still works, so
     * nobody who signed up just before a deploy gets stuck.
     */
    private function otpMatches(string $stored, string $typed): bool
    {
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
            return Hash::check($typed, $stored);
        }

        return hash_equals($stored, $typed);
    }

    private function otpAttemptsKey(string $email): string
    {
        return 'rider-otp-attempts:' . sha1(mb_strtolower($email));
    }
}

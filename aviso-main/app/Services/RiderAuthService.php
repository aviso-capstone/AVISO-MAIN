<?php

namespace App\Services;

use App\Mail\RiderPasswordResetMail;
use App\Mail\RiderVerificationMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class RiderAuthService
{
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

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $data['email'],
            'otp'        => $otp,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($data['email'])->send(new RiderVerificationMail($otp, $data['first_name']));
    }

    public function verifyOtp(string $email, string $otp): ?array
    {
        $record = PasswordResetOtp::where('email', $email)
            ->where('otp', $otp)
            ->latest()
            ->first();

        if (!$record || $record->isExpired()) {
            return null;
        }

        $user = User::where('email', $email)->where('role', 'rider')->first();

        if (!$user) {
            return null;
        }

        $user->update(['email_verified_at' => now()]);
        $record->delete();

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

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $email,
            'otp'        => $otp,
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

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::create([
            'user_id'    => $user->id,
            'email'      => $email,
            'otp'        => $otp,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($email)->send(new RiderPasswordResetMail($otp, $user->first_name));

        return true;
    }

    public function verifyForgotPasswordOtp(string $email, string $otp): ?string
    {
        $record = PasswordResetOtp::where('email', $email)
            ->where('otp', $otp)
            ->latest()
            ->first();

        if (!$record || $record->isExpired()) {
            return null;
        }

        $record->delete();

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

        return true;
    }

}

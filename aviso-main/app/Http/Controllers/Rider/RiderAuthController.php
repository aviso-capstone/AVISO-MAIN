<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\LoginRequest;
use App\Http\Requests\Rider\RegisterRequest;
use App\Services\RiderAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderAuthController extends Controller
{
    public function __construct(private RiderAuthService $riderAuthService)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->riderAuthService->attemptLogin(
            $request->input('email'),
            $request->password
        );

        if (!$result) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        return response()->json($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->riderAuthService->logout($request->user());

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->riderAuthService->register($request->validated());

        return response()->json(['message' => 'Verification code sent to your email.'], 201);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'digits:6'],
        ]);

        $result = $this->riderAuthService->verifyOtp($request->email, $request->otp);

        if (!$result) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        return response()->json($result);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $sent = $this->riderAuthService->resendOtp($request->email);

        if (!$sent) {
            return response()->json(['message' => 'No unverified account found for this email.'], 422);
        }

        return response()->json(['message' => 'Verification code resent.']);
    }

    public function sendForgotPasswordOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $sent = $this->riderAuthService->sendForgotPasswordOtp($request->email);

        if (!$sent) {
            return response()->json(['message' => 'No rider account found with this email address.'], 422);
        }

        return response()->json(['message' => 'Reset code sent to your email.']);
    }

    public function verifyForgotPasswordOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'digits:6'],
        ]);

        $token = $this->riderAuthService->verifyForgotPasswordOtp($request->email, $request->otp);

        if (!$token) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 422);
        }

        return response()->json(['reset_token' => $token]);
    }

    public function resetForgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'reset_token'           => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $success = $this->riderAuthService->resetPassword($request->reset_token, $request->password);

        if (!$success) {
            return response()->json(['message' => 'Invalid or expired reset token. Please start over.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}

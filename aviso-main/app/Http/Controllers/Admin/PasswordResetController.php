<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    protected $passwordResetService;

    public function __construct(PasswordResetService $passwordResetService)
    {
        $this->passwordResetService = $passwordResetService;
    }

    /**
     * Render the forgot password wizard page.
     * All 3 steps live on this single page as React state.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Step 1 — Send OTP to the provided email address.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $this->passwordResetService->sendOtp($validated['email']);

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    /**
     * Step 2 — Verify the 6-digit OTP and return a signed reset token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'string', 'size:6'],
        ]);

        try {
            $token = $this->passwordResetService->verifyOtp(
                $validated['email'],
                $validated['otp'],
            );

            return response()->json([
                'success' => true,
                'token'   => $token,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    /**
     * Step 3 — Reset the password using the signed token from Step 2.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)->mixedCase(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        // Validate the signed token — if tampered or expired, abort
        if (!$request->hasValidSignature()) {
            return response()->json([
                'success' => false,
                'errors'  => ['token' => 'Your reset session has expired. Please start over.'],
            ], 422);
        }

        try {
            $this->passwordResetService->resetPassword(
                $validated['email'],
                $validated['password'],
            );

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors'  => $e->errors(),
            ], 422);
        }
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForgotPasswordEmailRequest;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Http\Requests\Admin\VerifyResetOtpRequest;
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
    public function sendOtp(ForgotPasswordEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Same answer whether or not the email is an admin — no account enumeration.
        $this->passwordResetService->sendOtp($validated['email']);

        return response()->json([
            'success' => true,
            'message' => 'If that email belongs to an admin account, an OTP has been sent.',
        ]);
    }

    /**
     * Step 2 — Verify the 6-digit OTP and return a signed reset token.
     */
    public function verifyOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();

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
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Validate the signed token — if tampered or expired, abort
        if (!$request->hasValidSignature()) {
            return response()->json([
                'success' => false,
                'errors'  => ['token' => 'Your reset session has expired. Please start over.'],
            ], 422);
        }

        try {
            // Email and nonce come from the signed query string only. A body
            // field would override it in $request->input() and let one admin's
            // link reset another admin's password.
            $this->passwordResetService->resetPassword(
                (string) $request->query('email'),
                (string) $request->query('nonce'),
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

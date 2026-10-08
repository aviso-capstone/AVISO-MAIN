<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Rider\AddressController;
use App\Http\Controllers\Rider\EmergencyContactController;
use App\Http\Controllers\Rider\EmergencyController;
use App\Http\Controllers\Rider\HazardLogController;
use App\Http\Controllers\Rider\ProfileController;
use App\Http\Controllers\Rider\RiderAuthController;
use App\Http\Controllers\Rider\TripController;
use App\Http\Controllers\Rider\TripHistoryController;
use App\Http\Controllers\Rider\TtsController;
use App\Http\Middleware\RequireRiderRole;

// All routes here are loaded by the RouteServiceProvider
// and prefixed with /api/rider via the api route group

Route::prefix('rider')->group(function () {

    // ── Auth (public) ──────────────────────────────────────────────────────────
    Route::post('/auth/login',       [RiderAuthController::class, 'login']);
    Route::post('/auth/register',    [RiderAuthController::class, 'register']);
    Route::post('/auth/verify-otp',  [RiderAuthController::class, 'verifyOtp']);
    Route::post('/auth/resend-otp',  [RiderAuthController::class, 'resendOtp']);

    Route::post('/auth/forgot-password',        [RiderAuthController::class, 'sendForgotPasswordOtp']);
    Route::post('/auth/forgot-password/verify', [RiderAuthController::class, 'verifyForgotPasswordOtp']);
    Route::post('/auth/forgot-password/reset',  [RiderAuthController::class, 'resetForgotPassword']);

    // ── Address lookup (public — PSGC data needs no auth, used by signup too) ─
    Route::get('/address/provinces',         [AddressController::class, 'provinces']);
    Route::get('/address/cities/{province}', [AddressController::class, 'cities']);
    Route::get('/address/barangays/{city}',  [AddressController::class, 'barangays']);

    // ── Protected (RequireRiderRole validates Sanctum token + role) ────────────
    Route::middleware(RequireRiderRole::class)->group(function () {

        Route::post('/auth/logout', [RiderAuthController::class, 'logout']);

        // ── Hazard logs ────────────────────────────────────────────────────────
        Route::get('/hazard-logs',  [HazardLogController::class, 'index']);
        Route::post('/hazard-logs', [HazardLogController::class, 'store']);

        // ── All active hazards (community map layer — same data admin sees) ────
        Route::get('/hazards', [HazardLogController::class, 'active']);

        // ── Trip tracking ──────────────────────────────────────────────────────
        Route::post('/trips',                    [TripController::class, 'start']);
        Route::put('/trips/{trip}/location',     [TripController::class, 'updateLocation']);
        Route::put('/trips/{trip}/end',          [TripController::class, 'end']);

        // ── Trip history ───────────────────────────────────────────────────────
        Route::get('/trips',          [TripHistoryController::class, 'index']);
        Route::get('/trips/{trip}',   [TripHistoryController::class, 'show']);

        // ── Emergency SOS ──────────────────────────────────────────────────────
        Route::post('/emergency/sos', [EmergencyController::class, 'sos']);

        // ── Emergency contacts ─────────────────────────────────────────────────
        Route::get('/emergency-contacts',              [EmergencyContactController::class, 'index']);
        Route::post('/emergency-contacts',             [EmergencyContactController::class, 'store']);
        Route::put('/emergency-contacts/{contact}',   [EmergencyContactController::class, 'update']);
        Route::delete('/emergency-contacts/{contact}',[EmergencyContactController::class, 'destroy']);

        // ── Rider profile ──────────────────────────────────────────────────────
        Route::patch('/profile',          [ProfileController::class, 'update']);
        Route::patch('/profile/personal', [ProfileController::class, 'updatePersonalInfo']);
        Route::post('/profile/avatar',    [ProfileController::class, 'uploadAvatar']);

        // ── Text-to-speech proxy (OpenAI key stays server-side, never in mobile bundle) ──
        Route::post('/tts', [TtsController::class, 'speak']);

    });
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Rider\AddressController;
use App\Http\Controllers\Rider\EmergencyContactController;
use App\Http\Controllers\Rider\EmergencyController;
use App\Http\Controllers\Rider\HazardLogController;
use App\Http\Controllers\Rider\HazardReportController;
use App\Http\Controllers\Rider\IotDeviceController;
use App\Http\Controllers\Rider\NotificationController;
use App\Http\Controllers\Rider\ProfileController;
use App\Http\Controllers\Rider\RiderAuthController;
use App\Http\Controllers\Rider\RiderEventController;
use App\Http\Controllers\Rider\TripController;
use App\Http\Controllers\Rider\TripHistoryController;
use App\Http\Controllers\Rider\TtsController;
use App\Http\Middleware\RequireRiderRole;

// All routes here are loaded by the RouteServiceProvider
// and prefixed with /api/rider via the api route group

Route::prefix('rider')->group(function () {

    // ── Auth (public) ──────────────────────────────────────────────────────────
    Route::post('/auth/login',       [RiderAuthController::class, 'login'])->middleware('throttle:rider-login');
    Route::post('/auth/register',    [RiderAuthController::class, 'register'])->middleware('throttle:rider-send-code');
    Route::post('/auth/verify-otp',  [RiderAuthController::class, 'verifyOtp'])->middleware('throttle:rider-check-code');
    Route::post('/auth/resend-otp',  [RiderAuthController::class, 'resendOtp'])->middleware('throttle:rider-send-code');

    Route::post('/auth/forgot-password',        [RiderAuthController::class, 'sendForgotPasswordOtp'])->middleware('throttle:rider-send-code');
    Route::post('/auth/forgot-password/verify', [RiderAuthController::class, 'verifyForgotPasswordOtp'])->middleware('throttle:rider-check-code');
    Route::post('/auth/forgot-password/reset',  [RiderAuthController::class, 'resetForgotPassword'])->middleware('throttle:rider-reset-password');

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

        // ── "Hazard no longer there" report (photo proof, admin reviews it) ───
        Route::post('/hazards/{hazard}/reports', [HazardReportController::class, 'store'])->middleware('throttle:10,1');

        // ── Trip tracking ──────────────────────────────────────────────────────
        Route::post('/trips',                    [TripController::class, 'start']);
        Route::put('/trips/{trip}/location',     [TripController::class, 'updateLocation']);
        Route::put('/trips/{trip}/end',          [TripController::class, 'end']);

        // ── Trip history ───────────────────────────────────────────────────────
        Route::get('/trips',          [TripHistoryController::class, 'index']);
        Route::get('/trips/{trip}',   [TripHistoryController::class, 'show']);

        // ── Emergency SOS ──────────────────────────────────────────────────────
        Route::post('/emergency/sos', [EmergencyController::class, 'sos']);
        Route::get('/emergency/latest', [EmergencyController::class, 'latest'])->middleware('throttle:30,1');

        // ── IoT crash-detection unit ───────────────────────────────────────────
        Route::get('/iot/device',           [IotDeviceController::class, 'show']);
        Route::post('/iot/pairing-code',    [IotDeviceController::class, 'pairingCode'])->middleware('throttle:5,1');
        Route::delete('/iot/device',        [IotDeviceController::class, 'destroy']);

        // ── Crash-detection log from the IoT unit (all 4 categories) ──────────
        Route::get('/events',         [RiderEventController::class, 'index']);
        Route::get('/events/stats',   [RiderEventController::class, 'stats']);
        Route::get('/events/export',  [RiderEventController::class, 'export'])->middleware('throttle:10,1');
        Route::post('/events',        [RiderEventController::class, 'store'])->middleware('throttle:120,1');

        // ── Emergency contacts ─────────────────────────────────────────────────
        Route::get('/emergency-contacts',              [EmergencyContactController::class, 'index']);
        Route::post('/emergency-contacts',             [EmergencyContactController::class, 'store']);
        Route::put('/emergency-contacts/{contact}',   [EmergencyContactController::class, 'update']);
        Route::delete('/emergency-contacts/{contact}',[EmergencyContactController::class, 'destroy']);

        // ── Rider profile ──────────────────────────────────────────────────────
        Route::patch('/profile',          [ProfileController::class, 'update']);
        Route::patch('/profile/personal', [ProfileController::class, 'updatePersonalInfo']);
        Route::post('/profile/avatar',    [ProfileController::class, 'uploadAvatar']);

        // ── Notifications screen (the bell on Home) ───────────────────────────
        Route::get('/notifications',              [NotificationController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle:60,1');
        Route::post('/notifications/read-all',    [NotificationController::class, 'markAllRead'])->middleware('throttle:30,1');
        Route::post('/notifications/{id}/read',   [NotificationController::class, 'markRead'])->middleware('throttle:60,1');

        // ── Text-to-speech proxy (OpenAI key stays server-side, never in mobile bundle) ──
        Route::post('/tts', [TtsController::class, 'speak'])->middleware('throttle:20,1');

    });
});

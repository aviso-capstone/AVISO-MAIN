<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Middleware\RequireAdminRole;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('web')->group(function () {
    // Redirect root to login
    Route::redirect('/', '/login');

    // Admin Dashboard (Protected by session auth + admin role check)
    Route::middleware(['auth', RequireAdminRole::class])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        Route::get('/map', function (\Illuminate\Http\Request $request) {
            $activeHazards = \App\Models\HazardLog::where('status', 'active')->get();
            $settings = \App\Models\SystemSetting::instance();

            $focusAlert = null;
            if ($request->filled('alert')) {
                $alert = \App\Models\EmergencyAlert::with('user')->find($request->query('alert'));
                if ($alert) {
                    $focusAlert = (new \App\Events\EmergencyAlertTriggered($alert))->broadcastWith();
                }
            }

            // Note: unresolved alerts are NOT passed here. They arrive through
            // the `sos` prop HandleInertiaRequests shares with every admin page,
            // so the global banner, the sidebar badge and this map all read one
            // list. `focusAlert` stays local — it is the map-only history link,
            // and it deliberately loads any alert by id regardless of status.
            return Inertia::render('main/MapPage', [
                'hazards'               => $activeHazards,
                'emergencyHazardTypes'  => $settings->emergency_hazard_types,
                'focusAlert'            => $focusAlert,
            ]);
        })->name('map');
        
        Route::get('/hazards', [\App\Http\Controllers\Admin\HazardLogsController::class, 'index'])->name('hazards.index');

        // Settings
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/profile', [\App\Http\Controllers\Admin\SettingsController::class, 'updateProfile'])->name('settings.profile');
        Route::post('/settings/password', [\App\Http\Controllers\Admin\SettingsController::class, 'updatePassword'])->name('settings.password');
        Route::post('/settings/system', [\App\Http\Controllers\Admin\SettingsController::class, 'updateSystem'])->name('settings.system');
        
        // Users Management
        Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
        Route::post('/users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('users.destroy');

        // Emergency Contacts
        Route::get('/users/{user}/emergency-contacts', [\App\Http\Controllers\Admin\EmergencyContactController::class, 'index'])->name('users.emergency-contacts.index');
        Route::post('/users/{user}/emergency-contacts', [\App\Http\Controllers\Admin\EmergencyContactController::class, 'store'])->name('users.emergency-contacts.store');
        Route::delete('/emergency-contacts/{contact}', [\App\Http\Controllers\Admin\EmergencyContactController::class, 'destroy'])->name('users.emergency-contacts.destroy');

        // ── SOS Alert History ──────────────────────────────────────────────
        Route::get('/sos-alerts', [\App\Http\Controllers\Admin\EmergencyAlertController::class, 'index'])->name('sos-alerts.index');
        Route::get('/sos-alerts/{user}/history', [\App\Http\Controllers\Admin\EmergencyAlertController::class, 'history'])->name('sos-alerts.history');
        Route::put('/sos-alerts/{alert}/resolve', [\App\Http\Controllers\Admin\EmergencyAlertController::class, 'resolve'])->name('sos-alerts.resolve');

        // ── Live Rider Tracking (JSON API for the map) ─────────────────────
        Route::get('/api/trips/active', [\App\Http\Controllers\Admin\TripController::class, 'activeRiders'])->name('trips.active');

        // ── Jarvis TTS — admin SOS alarm (Gemini Charon voice, cached WAV) ─
        Route::get('/jarvis/sos', [\App\Http\Controllers\Admin\JarvisTtsController::class, 'sos'])->name('jarvis.sos');
        Route::get('/jarvis/rider-alert', [\App\Http\Controllers\Admin\JarvisTtsController::class, 'riderAlert'])->name('jarvis.rider-alert');
        
        Route::post('logout', [AdminAuthController::class, 'destroy'])->name('logout');
    });

    // Admin Authentication Routes
    Route::middleware('guest')->group(function () {
        Route::get('login', [AdminAuthController::class, 'create'])->name('login');
        Route::post('login', [AdminAuthController::class, 'store'])->middleware('throttle:6,1'); // 6 login attempts per minute

        // ── OTP Password Reset Wizard ───────────────────────────────────────
        Route::get('forgot-password', [PasswordResetController::class, 'show'])->name('password.request');
        Route::post('forgot-password/send-otp', [PasswordResetController::class, 'sendOtp'])->name('password.send-otp')->middleware('throttle:3,1');
        Route::post('forgot-password/verify-otp', [PasswordResetController::class, 'verifyOtp'])->name('password.verify-otp')->middleware('throttle:5,1');
        Route::post('forgot-password/reset', [PasswordResetController::class, 'reset'])->name('password.otp.token');
    });
});
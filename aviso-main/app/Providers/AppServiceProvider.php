<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRiderAuthLimits();
    }

    /**
     * Rider sign-up, login and code limits. Each account (the email or
     * username typed) gets its own small limit, so one person guessing can't
     * lock out everyone else on the same Wi-Fi. The larger per-network limit
     * only stops a flood from one place (many riders can share a school or
     * event Wi-Fi, such as during the defense).
     *
     * A 429 answer has a "message", which the app already shows in its toast.
     */
    private function configureRiderAuthLimits(): void
    {
        $account = fn (Request $request): string => mb_strtolower(trim((string) $request->input('email')));

        $limits = [
            // name           => [per account / minute, per network / minute]
            'rider-login'    => [6, 60],
            'rider-send-code' => [3, 30], // register, resend code, forgot password (each sends an email)
            'rider-check-code' => [10, 60], // verify sign-up code, verify reset code
        ];

        foreach ($limits as $name => [$perAccount, $perNetwork]) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute($perAccount)->by($name . ':account:' . $account($request) . '|' . $request->ip()),
                Limit::perMinute($perNetwork)->by($name . ':network:' . $request->ip()),
            ]);
        }

        // The last reset step carries a one-time token, not an email.
        RateLimiter::for('rider-reset-password', fn (Request $request) => [
            Limit::perMinute(30)->by('rider-reset-password:' . $request->ip()),
        ]);
    }
}

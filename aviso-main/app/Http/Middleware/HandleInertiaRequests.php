<?php

namespace App\Http\Middleware;

use App\Services\EmergencyAlertService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'info' => $request->session()->get('info'),
                'error' => $request->session()->get('error'),
            ],
            // Unresolved SOS alerts, so the global banner and sidebar badge are
            // correct on the very first paint of any admin page. Wrapped in a
            // closure so Inertia resolves it lazily — the query never runs for
            // guests or riders, and rider PII never reaches a non-admin response.
            'sos' => fn (): ?array => $request->user()?->role === 'admin'
                ? ['pending' => app(EmergencyAlertService::class)->getUnresolvedForBroadcast()]
                : null,
        ];
    }
}

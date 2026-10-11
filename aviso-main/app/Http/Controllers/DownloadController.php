<?php

namespace App\Http\Controllers;

use App\Services\ExpoBuildService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public (no login) page for downloading the aviso-mobile APK.
 */
class DownloadController extends Controller
{
    public function __construct(private readonly ExpoBuildService $expoBuilds) {}

    public function index(): Response
    {
        $build = $this->expoBuilds->latestApk();

        return Inertia::render('Download', [
            // The page links to /download/apk, so the raw artifact URL stays server-side.
            'build' => Arr::except($build, 'download_url'),
        ]);
    }

    /**
     * Sends the phone straight to Expo's copy of the APK instead of proxying
     * ~100 MB through this server.
     */
    public function apk(): RedirectResponse
    {
        $build = $this->expoBuilds->latestApk();

        abort_if(isset($build['error']), 503, $build['error'] ?? '');

        abort_if(
            $build['expires_at'] && CarbonImmutable::parse($build['expires_at'])->isPast(),
            410,
            'This download has expired. A new version is coming soon.'
        );

        return redirect()->away($build['download_url']);
    }
}

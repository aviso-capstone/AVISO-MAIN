<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Looks up the newest installable aviso-mobile APK on EAS.
 *
 * Nothing is uploaded to this server: every `eas build --profile preview`
 * lands on Expo, and the public /download page reads it from Expo's GraphQL
 * API. Results are cached so page visits don't each hit Expo.
 */
class ExpoBuildService
{
    private const CACHE_KEY = 'expo.latest_apk';
    private const CACHE_SECONDS = 300;
    private const ERROR_CACHE_SECONDS = 30;
    private const APK_PROFILE = 'preview';
    private const DEFAULT_RELEASE_NOTES = 'Bug fixes and performance improvements.';

    private const QUERY = <<<'GQL'
    query LatestAndroidBuilds($appId: String!) {
        app {
            byId(appId: $appId) {
                builds(filter: { platform: ANDROID, status: FINISHED }, limit: 10, offset: 0) {
                    appVersion
                    appBuildVersion
                    buildProfile
                    message
                    createdAt
                    expirationDate
                    artifacts { applicationArchiveUrl }
                }
            }
        }
    }
    GQL;

    /**
     * @return array{download_url: string, version: string, build_number: ?string, release_notes: string, built_at: string, expires_at: ?string, file_size: ?string}|array{error: string}
     */
    public function latestApk(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetchLatestApk();

        $ttl = isset($result['error'])
            ? self::ERROR_CACHE_SECONDS
            : $this->successTtl($result['expires_at']);

        Cache::put(self::CACHE_KEY, $result, $ttl);

        return $result;
    }

    private function fetchLatestApk(): array
    {
        $projectId = config('services.expo.project_id');
        $token = config('services.expo.access_token');

        if (empty($projectId) || empty($token)) {
            return ['error' => 'The download page is not configured yet.'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post('https://api.expo.dev/graphql', [
                    'query'     => self::QUERY,
                    'variables' => ['appId' => $projectId],
                ]);
        } catch (\Throwable $e) {
            Log::error('[ExpoBuildService] Expo API unreachable', ['error' => $e->getMessage()]);
            return ['error' => 'Unable to reach the build server. Please try again later.'];
        }

        if (! $response->successful() || $response->json('errors')) {
            // Log the detail server-side only — never echo Expo errors to the public page.
            Log::warning('[ExpoBuildService] Expo API error', [
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);
            return ['error' => 'Unable to reach the build server. Please try again later.'];
        }

        $build = $this->pickApk($response->json('data.app.byId.builds') ?? []);

        if (! $build) {
            return ['error' => 'No app download is available yet. Please check back later.'];
        }

        $url = $build['artifacts']['applicationArchiveUrl'];

        return [
            'download_url'   => $url,
            'version'        => $build['appVersion'] ?? '—',
            // Auto-incremented by EAS on every build (eas.json autoIncrement).
            'build_number'   => $build['appBuildVersion'] ?? null,
            // Rider-facing notes from `eas build --message "..."`. The git commit
            // message is developer jargon and is never shown.
            'release_notes'  => $this->releaseNotes($build['message'] ?? null),
            'built_at'       => CarbonImmutable::parse($build['createdAt'])->toIso8601String(),
            'expires_at'     => isset($build['expirationDate'])
                ? CarbonImmutable::parse($build['expirationDate'])->toIso8601String()
                : null,
            'file_size'      => $this->fileSize($url),
        ];
    }

    /**
     * Newest preview build with an .apk artifact (production builds are .aab,
     * which phones can't install). Falls back to any .apk if none is tagged preview.
     */
    private function pickApk(array $builds): ?array
    {
        $apks = array_values(array_filter($builds, function (array $build): bool {
            $url = $build['artifacts']['applicationArchiveUrl'] ?? null;
            return $url && str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.apk');
        }));

        foreach ($apks as $build) {
            if (($build['buildProfile'] ?? null) === self::APK_PROFILE) {
                return $build;
            }
        }

        return $apks[0] ?? null;
    }

    private function releaseNotes(?string $message): string
    {
        $message = trim((string) $message);

        return $message === '' ? self::DEFAULT_RELEASE_NOTES : mb_substr($message, 0, 500);
    }

    /** Never cache a build past its own expiry. */
    private function successTtl(?string $expiresAt): int
    {
        if (! $expiresAt) {
            return self::CACHE_SECONDS;
        }

        $secondsLeft = CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($expiresAt), false);

        return (int) max(1, min(self::CACHE_SECONDS, $secondsLeft));
    }

    /** Best-effort; the page works without it. */
    private function fileSize(string $url): ?string
    {
        try {
            $bytes = Http::timeout(10)->head($url)->header('Content-Length');
            if (is_numeric($bytes) && (int) $bytes > 0) {
                return round((int) $bytes / 1048576, 1) . ' MB';
            }
        } catch (\Throwable) {
            // file size is cosmetic
        }

        return null;
    }
}

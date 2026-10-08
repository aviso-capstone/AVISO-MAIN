<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Reverse-geocode coordinates to a short human-readable place name.
     * Best-effort: never throws, so a geocoding failure can never block
     * an emergency alert from being dispatched.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $token = config('services.mapbox.token');

        if (empty($token)) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->get("https://api.mapbox.com/geocoding/v5/mapbox.places/{$lng},{$lat}.json", [
                    'types'         => 'neighborhood,locality,district,place',
                    'access_token'  => $token,
                    'language'      => 'en',
                    'limit'         => 1,
                ]);

            if (!$response->successful()) {
                Log::warning('[GeocodingService] Reverse geocode failed', [
                    'lat' => $lat, 'lng' => $lng, 'status' => $response->status(),
                ]);
                return null;
            }

            $feature = $response->json('features.0');

            if (!$feature) {
                return null;
            }

            $context = collect($feature['context'] ?? []);
            $neighborhood = $context->firstWhere(fn ($c) => str_starts_with($c['id'], 'neighborhood'))['text'] ?? null;
            $locality    = $context->firstWhere(fn ($c) => str_starts_with($c['id'], 'locality'))['text'] ?? null;
            $district    = $context->firstWhere(fn ($c) => str_starts_with($c['id'], 'district'))['text'] ?? null;

            return $neighborhood ?? $locality ?? $district ?? $feature['text'] ?? null;
        } catch (\Throwable $e) {
            Log::error('[GeocodingService] Exception during reverse geocode', [
                'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

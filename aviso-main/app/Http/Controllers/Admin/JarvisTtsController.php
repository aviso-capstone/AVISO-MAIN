<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TtsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JarvisTtsController extends Controller
{
    private const SOS_TEXT  = 'Alert. Emergency S.O.S. triggered. Immediate assistance is required. Rider location has been pinned on the map.';
    private const CACHE_KEY = 'admin_sos_alert';

    public function __construct(private readonly TtsService $tts) {}

    public function sos(): Response
    {
        $wavBytes = $this->tts->getOrGenerate(self::SOS_TEXT, self::CACHE_KEY);

        return response($wavBytes, 200)
            ->header('Content-Type', 'audio/wav')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    public function riderAlert(Request $request): Response
    {
        $name     = substr(strip_tags((string) $request->query('name', 'the rider')), 0, 60);
        $slug     = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)), 0, 40);
        $slug     = trim($slug, '_') ?: 'rider';
        $cacheKey = 'sos_rider_' . $slug;

        $text     = "Alert. Emergency S.O.S. triggered by {$name}. Immediate assistance required. Rider location has been pinned on the map.";
        $wavBytes = $this->tts->getOrGenerate($text, $cacheKey);

        return response($wavBytes, 200)
            ->header('Content-Type', 'audio/wav')
            ->header('Cache-Control', 'private, max-age=86400');
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TtsService
{
    private const SAMPLE_RATE = 24000;
    private const CHANNELS    = 1;
    private const BITS        = 16;
    private const VOICE       = 'Charon';
    private const MODEL       = 'gemini-3.1-flash-tts-preview';

    public function getOrGenerate(string $text, string $cacheKey): string
    {
        abort_unless(preg_match('/^[a-z0-9_\-]{1,64}$/', $cacheKey), 400, 'Invalid cache key.');

        $cachePath = "tts_cache/{$cacheKey}.wav";

        if (Storage::exists($cachePath)) {
            return Storage::get($cachePath);
        }

        $apiKey = config('services.gemini.key');
        abort_if(empty($apiKey), 503, 'TTS service unavailable.');

        $gemini = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(20)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/" . self::MODEL . ":generateContent", [
                'contents'        => [['parts' => [['text' => $text]]]],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig'       => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => ['voiceName' => self::VOICE],
                        ],
                    ],
                ],
            ]);

        if (!$gemini->successful()) {
            Log::error('[TtsService] Gemini API error', [
                'status'    => $gemini->status(),
                'body'      => $gemini->body(),
                'cache_key' => $cacheKey,
            ]);
            abort(502, 'TTS generation failed.');
        }

        $pcmBase64 = $gemini->json('candidates.0.content.parts.0.inlineData.data');
        abort_if(empty($pcmBase64), 502, 'TTS returned empty audio.');

        $wavBytes = $this->pcmToWav(base64_decode($pcmBase64));
        Storage::put($cachePath, $wavBytes);

        return $wavBytes;
    }

    private function pcmToWav(string $pcm): string
    {
        $rate       = self::SAMPLE_RATE;
        $channels   = self::CHANNELS;
        $bits       = self::BITS;
        $dataLen    = strlen($pcm);
        $byteRate   = $rate * $channels * ($bits / 8);
        $blockAlign = $channels * ($bits / 8);

        return pack(
            'A4VA4A4VvvVVvvA4V',
            'RIFF', 36 + $dataLen, 'WAVE',
            'fmt ', 16, 1, $channels, $rate, $byteRate, $blockAlign, $bits,
            'data', $dataLen,
        ) . $pcm;
    }
}

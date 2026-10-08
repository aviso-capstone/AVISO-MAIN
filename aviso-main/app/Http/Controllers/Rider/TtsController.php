<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\TtsRequest;
use App\Services\TtsService;
use Illuminate\Http\Response;

class TtsController extends Controller
{
    public function __construct(private readonly TtsService $tts) {}

    public function speak(TtsRequest $request): Response
    {
        $wavBytes = $this->tts->getOrGenerate(
            $request->validated('text'),
            $request->validated('cache_key'),
        );

        return response($wavBytes, 200)
            ->header('Content-Type', 'audio/wav')
            ->header('Cache-Control', 'private, max-age=86400');
    }
}

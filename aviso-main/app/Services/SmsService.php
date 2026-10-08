<?php

namespace App\Services;

use App\Models\EmergencyContact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send an SMS via SkySMS. Best-effort: never throws, so a gateway
     * failure can never break the caller (e.g. the rider's SOS response).
     */
    public function send(string $recipientRaw, string $message): bool
    {
        $recipient = EmergencyContact::normalizeToE164Ph($recipientRaw);

        if (!$recipient) {
            Log::warning('[SmsService] Skipped send — unrecognized phone format', ['raw' => $recipientRaw]);
            return false;
        }

        $apiKey = config('services.skysms.key');

        if (empty($apiKey)) {
            Log::warning('[SmsService] SKYSMS_KEY not configured — skipping send.');
            return false;
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(10)
                ->post(config('services.skysms.url'), [
                    'phone_number' => $recipient,
                    'message'      => $message,
                ]);

            if (!$response->successful() || !$response->json('success')) {
                Log::error('[SmsService] Send failed', [
                    'recipient' => $recipient,
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                ]);
                return false;
            }

            // SkySMS marks content-policy violations (URLs, profanity) as "sent" at
            // the HTTP layer but never delivers them — a `warning` field is the only
            // signal. Treat it as a failed send so it isn't mistaken for a delivered SOS.
            if ($warning = $response->json('warning')) {
                Log::error('[SmsService] Message blocked by content policy — not delivered', [
                    'recipient' => $recipient,
                    'warning'   => $warning,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[SmsService] Exception during send', [
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}

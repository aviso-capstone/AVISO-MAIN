<?php

namespace App\Services;

use App\Models\EmergencyContact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send an SMS through the gateway chosen by services.sms_provider
     * (UniSMS by default, SkySMS kept as the alternative). Best-effort:
     * never throws, so a gateway failure can never break the caller
     * (e.g. the rider's SOS response).
     */
    public function send(string $recipientRaw, string $message): bool
    {
        $recipient = EmergencyContact::normalizeToE164Ph($recipientRaw);

        if (!$recipient) {
            Log::warning('[SmsService] Skipped send — unrecognized phone format', ['raw' => $recipientRaw]);
            return false;
        }

        try {
            return config('services.sms_provider') === 'skysms'
                ? $this->sendViaSkySms($recipient, $message)
                : $this->sendViaUniSms($recipient, $message);
        } catch (\Throwable $e) {
            Log::error('[SmsService] Exception during send', [
                'recipient' => $recipient,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * UniSMS: Basic auth with the secret key as username and an empty password.
     * A 201 means the message was queued ("pending"). The default test sender ID
     * cannot reach Smart, TNT or Sun numbers; UniSMS refuses those with a 422.
     */
    private function sendViaUniSms(string $recipient, string $message): bool
    {
        $apiKey = config('services.unismsapi.key');

        if (empty($apiKey)) {
            Log::warning('[SmsService] UNISMSAPI_KEY not configured — skipping send.');
            return false;
        }

        $response = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->timeout(10)
            ->post(config('services.unismsapi.url'), [
                'recipient' => $recipient,
                'content'   => $message,
                'sender_id' => config('services.unismsapi.sender_id'),
            ]);

        $status = $response->json('message.status');

        if ($response->status() !== 201 || $status === 'failed' || $response->json('message.fail_reason')) {
            Log::error('[SmsService] UniSMS send failed', [
                'recipient' => $recipient,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    private function sendViaSkySms(string $recipient, string $message): bool
    {
        $apiKey = config('services.skysms.key');

        if (empty($apiKey)) {
            Log::warning('[SmsService] SKYSMS_KEY not configured — skipping send.');
            return false;
        }

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->timeout(10)
            ->post(config('services.skysms.url'), [
                'phone_number' => $recipient,
                'message'      => $message,
            ]);

        if (!$response->successful() || !$response->json('success')) {
            Log::error('[SmsService] SkySMS send failed', [
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
    }
}

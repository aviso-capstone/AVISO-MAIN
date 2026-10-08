<?php

namespace App\Services;

use App\Events\EmergencyAlertResolved;
use App\Events\EmergencyAlertTriggered;
use App\Models\EmergencyAlert;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class EmergencyAlertService
{
    public function __construct(
        private SmsService $smsService,
        private GeocodingService $geocodingService,
        private BarangayLocatorService $barangayLocator,
    ) {
        //
    }

    /**
     * Raise (or reuse) an SOS alert for a rider.
     *
     * $triggeredAt is when the incident happened on the device. It is the
     * idempotency key: the mobile app retries an unsent SOS on a timer, and
     * without this every retry that lands after an admin resolved the alert
     * would insert a fresh pending row and re-SMS every contact.
     */
    public function triggerSos(
        User $rider,
        float $lat,
        float $lng,
        ?string $triggeredAt = null,
    ): EmergencyAlert {
        $incidentAt = $triggeredAt ? Carbon::parse($triggeredAt) : now();

        // Idempotency: the same incident re-sent by the app's retry queue must
        // never create a second alert, regardless of the first one's status.
        // Deliberately not scoped to pending — that was the bug. Mirrors the
        // ±5s window HazardLogService::processIncomingHazard already uses.
        $duplicate = EmergencyAlert::with('user')
            ->where('user_id', $rider->id)
            ->whereBetween('triggered_at', [
                $incidentAt->copy()->subSeconds(5),
                $incidentAt->copy()->addSeconds(5),
            ])
            ->first();

        if ($duplicate) {
            // Return the existing record untouched: no re-broadcast (the admin
            // may have already handled it) and no second round of SMS.
            return $duplicate;
        }

        // If rider already has a pending alert, update coords and re-broadcast the same record
        // instead of creating a new one — this keeps the admin map from getting duplicate pins
        // when crash detection fires multiple times for the same incident. Emergency contacts
        // are still notified on every trigger, with no throttling — an SOS must never go silent.
        $existing = EmergencyAlert::with('user')
            ->where('user_id', $rider->id)
            ->where('status', EmergencyAlert::STATUS_PENDING)
            ->latest()
            ->first();

        $barangay = $this->barangayLocator->locate($lat, $lng);

        if ($existing) {
            $existing->update([
                'latitude'      => $lat,
                'longitude'     => $lng,
                'barangay_code' => $barangay['code'] ?? null,
            ]);
            $this->broadcastQuietly(new EmergencyAlertTriggered($existing));
            $this->notifyEmergencyContacts($rider, $lat, $lng, $barangay['name'] ?? null);
            return $existing;
        }

        $alert = EmergencyAlert::create([
            'user_id'       => $rider->id,
            'rider_code'    => $rider->username ?? (string) $rider->id,
            'latitude'      => $lat,
            'longitude'     => $lng,
            'barangay_code' => $barangay['code'] ?? null,
            'triggered_at'  => $incidentAt,
            'status'        => EmergencyAlert::STATUS_PENDING,
        ]);

        $alert->setRelation('user', $rider);
        $this->broadcastQuietly(new EmergencyAlertTriggered($alert));

        $this->notifyEmergencyContacts($rider, $lat, $lng, $barangay['name'] ?? null);

        return $alert;
    }

    /**
     * SkySMS rejects (422) any single message over 160 characters, which would
     * silently drop the alert — so name/address are capped and the final
     * message is hard-truncated as a safety net. Worst-case (max-length name
     * + address) measures 151 chars, leaving margin below the cap.
     */
    private const SMS_MAX_LENGTH = 160;
    private const NAME_MAX       = 30;
    private const ADDRESS_MAX    = 25;

    /**
     * Static dispatch numbers shown to emergency contacts alongside every SOS,
     * sent as a second SMS. Mirrors the list in the admin live-map's
     * EmergencyHotlinesPanel — kept in sync manually since it's Zamboanga
     * City government data, not something derived from application state.
     */
    private const HOTLINES_MESSAGE =
        'AVISO Emergency Hotlines: ZCDRRMO 995-9601, EOC 0966-731-6242, '
        . 'Rescue 0926-091-2492, EMS 926-1848, Fire 991-2267';

    /**
     * SMS every registered emergency contact of the rider. Called on every
     * SOS trigger, whether it creates a new alert or updates an existing
     * pending one — never throttled, an SOS must never go silent.
     *
     * Two messages per contact: the alert itself (with a Google Maps search
     * instruction — plain coordinates, not a link, since SkySMS blocks and
     * never delivers any message containing a URL/domain), then the static
     * emergency hotlines so contacts know exactly who else to call.
     */
    private function notifyEmergencyContacts(User $rider, float $lat, float $lng, ?string $barangayName): void
    {
        $fullName = self::truncate(trim("{$rider->first_name} {$rider->last_name}"), self::NAME_MAX);
        // The official barangay (from the PSGC boundaries) is the most useful
        // place name for a contact; Mapbox is only asked when the rider is
        // outside Zamboanga City.
        $address  = $barangayName ?? $this->geocodingService->reverseGeocode($lat, $lng);
        $address  = $address ? self::truncate($address, self::ADDRESS_MAX) : null;

        $latStr = number_format($lat, 6, '.', '');
        $lngStr = number_format($lng, 6, '.', '');

        // Plain GPS coordinates, no URL — avoids SMS gateway link/content filters.
        $location = $address ? "near {$address} (GPS: {$latStr}, {$lngStr})" : "at GPS {$latStr}, {$lngStr}";
        $message  = self::truncate(
            "AVISO ALERT: {$fullName} triggered an SOS {$location}. Search the GPS in Google Maps.",
            self::SMS_MAX_LENGTH,
        );

        foreach ($rider->emergencyContacts as $contact) {
            $this->smsService->send($contact->contact_number, $message);
            $this->smsService->send($contact->contact_number, self::HOTLINES_MESSAGE);
        }
    }

    private static function truncate(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
    }

    public function getHistoryGroupedByRider(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->whereHas('emergencyAlerts')
            ->withCount('emergencyAlerts')
            ->withMax('emergencyAlerts', 'triggered_at')
            ->with(['emergencyAlerts' => fn ($q) => $q->latest('triggered_at')->limit(1)]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%"));
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        if (!in_array($perPage, [10, 15, 25, 50])) {
            $perPage = 15;
        }

        return $query->orderByDesc('emergency_alerts_max_triggered_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getAlertsForRider(User $rider): Collection
    {
        return $rider->emergencyAlerts()->orderByDesc('triggered_at')->get();
    }

    public function resolve(EmergencyAlert $alert): EmergencyAlert
    {
        $alert->update([
            'status'      => EmergencyAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        // Tell every connected admin view to drop it. Without this, a second
        // tab or a second operator keeps a red pin and a looping alarm.
        $this->broadcastQuietly(new EmergencyAlertResolved($alert));

        return $alert;
    }

    /**
     * Every alert still needing attention, serialized with the exact same
     * transform the live Reverb broadcast uses so the seeded list and the
     * pushed events share one shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUnresolvedForBroadcast(): array
    {
        return EmergencyAlert::with('user')
            ->unresolved()
            ->latest('triggered_at')
            ->get()
            ->map(fn (EmergencyAlert $alert) => (new EmergencyAlertTriggered($alert))->broadcastWith())
            ->values()
            ->toArray();
    }

    /**
     * Broadcast without letting a dead WebSocket server fail the request.
     *
     * These events use ShouldBroadcastNow, so they are dispatched inline over
     * HTTP to Reverb. If Reverb is down the Pusher client throws, and because
     * the database write has already committed the caller would report failure
     * for work that actually succeeded — an admin would be told "failed to
     * resolve" for an alert that is resolved, and the UI would put the alert
     * back. The database is the source of truth; live push is a convenience, so
     * a delivery failure is logged and swallowed. Anyone connected picks the
     * change up from shared props on their next page load.
     */
    private function broadcastQuietly(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('[EmergencyAlertService] Broadcast failed', [
                'event' => class_basename($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getAdminStats(): array
    {
        return [
            'total_today' => EmergencyAlert::whereDate('triggered_at', today())->count(),
            'total_week'  => EmergencyAlert::where('triggered_at', '>=', now()->subWeek())->count(),
            'pending'     => EmergencyAlert::pending()->count(),
            'resolved'    => EmergencyAlert::where('status', EmergencyAlert::STATUS_RESOLVED)->count(),
        ];
    }
}

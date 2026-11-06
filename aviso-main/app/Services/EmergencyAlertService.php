<?php

namespace App\Services;

use App\Events\EmergencyAlertResolved;
use App\Events\EmergencyAlertTriggered;
use App\Events\EmergencyAlertUpdated;
use App\Models\EmergencyAlert;
use App\Models\EmergencyContact;
use App\Models\User;
use App\Notifications\AdminNotifications;
use App\Notifications\RiderNotifications;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmergencyAlertService
{
    public function __construct(
        private SmsService $smsService,
        private GeocodingService $geocodingService,
        private BarangayLocatorService $barangayLocator,
        private NotificationService $notifications,
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
     *
     * $eventUid is the crash id from the IoT unit. The same crash can arrive
     * twice — from the phone after its countdown and from the device's own
     * Wi-Fi backup — and must still raise exactly one alert.
     */
    public function triggerSos(
        User $rider,
        float $lat,
        float $lng,
        ?string $triggeredAt = null,
        ?string $eventUid = null,
        string $source = EmergencyAlert::SOURCE_PHONE,
        ?string $triggerType = null,
    ): EmergencyAlert {
        if ($eventUid !== null) {
            $sameCrash = EmergencyAlert::with('user')->where('event_uid', $eventUid)->first();
            if ($sameCrash) {
                return $sameCrash;
            }
        }

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
                'event_uid'     => $existing->event_uid ?? $eventUid,
                'trigger_type'  => $existing->trigger_type ?? $triggerType,
            ]);
            $this->broadcastQuietly(new EmergencyAlertTriggered($existing));
            $this->notifyEmergencyContacts($existing, $rider, $lat, $lng, $barangay['name'] ?? null, $existing->trigger_type);
            return $existing;
        }

        $alert = EmergencyAlert::create([
            'user_id'       => $rider->id,
            'event_uid'     => $eventUid,
            'source'        => $source,
            'trigger_type'  => $triggerType,
            'rider_code'    => $rider->username ?? (string) $rider->id,
            'latitude'      => $lat,
            'longitude'     => $lng,
            'barangay_code' => $barangay['code'] ?? null,
            'triggered_at'  => $incidentAt,
            'status'        => EmergencyAlert::STATUS_PENDING,
        ]);

        $alert->setRelation('user', $rider);
        $this->broadcastQuietly(new EmergencyAlertTriggered($alert));

        // Notifications page: a new alert only (a repeat of a pending one is the same emergency).
        $this->notifications->notifyAdmins(AdminNotifications::sosTriggered($alert, $barangay['name'] ?? null));
        $this->notifications->notifyUser($rider, RiderNotifications::sosReceived($alert));

        $this->notifyEmergencyContacts($alert, $rider, $lat, $lng, $barangay['name'] ?? null, $triggerType);

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
    private function notifyEmergencyContacts(
        EmergencyAlert $alert,
        User $rider,
        float $lat,
        float $lng,
        ?string $barangayName,
        ?string $triggerType = null,
    ): void
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
        // The first words tell contacts how the SOS started. Worst case (manual,
        // max-length name + address) is 155 chars, still one SMS.
        $opening = match ($triggerType) {
            EmergencyAlert::TRIGGER_MANUAL => "AVISO SOS: {$fullName} reported an emergency",
            EmergencyAlert::TRIGGER_CRASH_IOT,
            EmergencyAlert::TRIGGER_CRASH_PHONE => "AVISO ALERT: Possible crash - {$fullName}",
            default => "AVISO ALERT: {$fullName} triggered an SOS",
        };
        $message  = self::truncate(
            "{$opening} {$location}. Search the GPS in Google Maps.",
            self::SMS_MAX_LENGTH,
        );

        foreach ($rider->emergencyContacts as $contact) {
            $sent = $this->smsService->send($contact->contact_number, $message);
            $this->smsService->send($contact->contact_number, self::HOTLINES_MESSAGE);

            if (! $sent) {
                $this->notifications->notifyAdmins(AdminNotifications::smsFailed($alert, $contact));
            }
        }
    }

    public const STEP_DISPATCHED = 'dispatched';
    public const STEP_ON_SCENE   = 'on_scene';
    public const STEP_RESOLVED   = 'resolved';

    /**
     * Text every emergency contact one short update about a checklist step.
     * Only the agency, ETA and outcome: never admin or responder names, never
     * the admin's note. Called once per step, after the step was saved (the
     * step order check stops a second click before it gets here).
     *
     * @return array{contacts: int, sent: int, failed: int}
     */
    public function textContactsAboutStep(EmergencyAlert $alert, string $step): array
    {
        $alert->loadMissing('user.emergencyContacts');
        $contacts = $alert->user?->emergencyContacts ?? collect();
        $message  = self::stepMessage($alert, $step);
        $sent     = 0;

        foreach ($contacts as $contact) {
            if ($this->smsService->send($contact->contact_number, $message)) {
                $sent++;
            } else {
                $this->notifications->notifyAdmins(AdminNotifications::smsFailed($alert, $contact));
            }
        }

        return ['contacts' => $contacts->count(), 'sent' => $sent, 'failed' => $contacts->count() - $sent];
    }

    /** The SMS for one step, always one message (160 characters at most). */
    public static function stepMessage(EmergencyAlert $alert, string $step): string
    {
        $rider = $alert->user;
        $name  = $rider
            ? self::truncate(trim("{$rider->first_name} {$rider->last_name}"), self::NAME_MAX)
            : 'the rider';
        $agency = $alert->agencyLabel();

        $text = match ($step) {
            self::STEP_DISPATCHED => "AVISO update: Help is on the way for {$name}"
                . ($agency ? ' - ' . implode(', ', array_filter([$agency, $alert->etaLabel()])) : '') . '.',
            self::STEP_ON_SCENE => 'AVISO update: Responders' . ($agency ? " ({$agency})" : '') . " have reached {$name}.",
            default => $alert->outcome === 'false_alarm'
                ? "AVISO update: The SOS from {$name} was a false alarm. No help is needed."
                : "AVISO update: {$name}'s emergency is resolved" . ($alert->outcomeLabel() ? ' - ' . $alert->outcomeLabel() : '') . '.',
        };

        return self::truncate($text, self::SMS_MAX_LENGTH);
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
        return $rider->emergencyAlerts()
            ->with('handler:id,first_name,last_name')
            ->orderByDesc('triggered_at')
            ->get()
            ->each(fn (EmergencyAlert $alert) => $alert->setAttribute('agency_label', $alert->agencyLabel())
                ->setAttribute('outcome_label', $alert->outcomeLabel())
                ->setAttribute('handled_by_name', $alert->handler
                    ? trim("{$alert->handler->first_name} {$alert->handler->last_name}")
                    : null));
    }

    /**
     * Step 2 of the dispatch checklist: the admin called an agency and help is
     * on the way. Uses the existing `acknowledged` status, which stops the
     * alarm and turns the pin orange.
     *
     * @param array{responder_agency: string, responder_agency_other?: ?string, eta_minutes?: ?int, responder_first_name?: ?string, responder_last_name?: ?string} $data
     */
    public function dispatch(EmergencyAlert $alert, array $data, User $admin): EmergencyAlert
    {
        $this->ensureStatus($alert, [EmergencyAlert::STATUS_PENDING], 'Help was already dispatched or the alert is resolved.');

        $isOther = $data['responder_agency'] === EmergencyAlert::AGENCY_OTHER;

        $alert->update([
            'status'                 => EmergencyAlert::STATUS_ACKNOWLEDGED,
            'dispatched_at'          => now(),
            'responder_agency'       => $data['responder_agency'],
            'responder_agency_other' => $isOther ? trim((string) ($data['responder_agency_other'] ?? '')) : null,
            'eta_minutes'            => $data['eta_minutes'] ?? null,
            'responder_first_name'   => self::cleanName($data['responder_first_name'] ?? null),
            'responder_last_name'    => self::cleanName($data['responder_last_name'] ?? null),
            'handled_by'             => $admin->id,
        ]);

        $alert = $this->afterUpdate($alert);
        $this->notifications->notifyAdmins(AdminNotifications::sosDispatched($alert, $admin));
        $this->notifications->notifyUser($alert->user, RiderNotifications::sosDispatched($alert));

        return $alert;
    }

    /** Step 3: the agency confirmed the responders reached the rider. */
    public function markOnScene(EmergencyAlert $alert, ?User $admin = null): EmergencyAlert
    {
        $this->ensureStatus($alert, [EmergencyAlert::STATUS_ACKNOWLEDGED], 'Mark help dispatched first.');

        if ($alert->on_scene_at !== null) {
            // Already on scene: nothing new to tell anyone.
            return $this->afterUpdate($alert);
        }

        $alert->update(['on_scene_at' => now()]);

        $alert = $this->afterUpdate($alert);
        $this->notifications->notifyAdmins(AdminNotifications::sosOnScene($alert, $admin));
        $this->notifications->notifyUser($alert->user, RiderNotifications::sosOnScene($alert));

        return $alert;
    }

    /**
     * Step 4: close the alert with how it ended.
     *
     * @param array{outcome: string, resolution_note?: ?string} $data
     */
    public function resolve(EmergencyAlert $alert, array $data, ?User $admin = null): EmergencyAlert
    {
        $this->ensureStatus(
            $alert,
            [EmergencyAlert::STATUS_PENDING, EmergencyAlert::STATUS_ACKNOWLEDGED],
            'This alert is already resolved.',
        );

        $note = trim((string) ($data['resolution_note'] ?? ''));

        $alert->update([
            'status'          => EmergencyAlert::STATUS_RESOLVED,
            'resolved_at'     => now(),
            'outcome'         => $data['outcome'],
            'resolution_note' => $note !== '' ? $note : null,
            // Resolved without a dispatch step (e.g. a false alarm): the admin
            // who closed it is the one on record.
            'handled_by'      => $alert->handled_by ?? $admin?->id,
        ]);

        // Tell every connected admin view to drop it. Without this, a second
        // tab or a second operator keeps a red pin and a looping alarm.
        $this->broadcastQuietly(new EmergencyAlertResolved($alert));

        $alert->load('user', 'handler');
        $this->notifications->notifyAdmins(AdminNotifications::sosResolved($alert, $admin));
        $this->notifications->notifyUser($alert->user, RiderNotifications::sosResolved($alert));

        return $alert;
    }

    /**
     * The rider's own most recent alert, as the SOS screen's status card shows
     * it. Only agency, ETA, times and outcome: no admin or responder names.
     */
    public function latestForRider(User $rider): ?array
    {
        $alert = $rider->emergencyAlerts()->latest('triggered_at')->first();

        return $alert ? self::riderStatus($alert) : null;
    }

    /** @return array<string, mixed> */
    public static function riderStatus(EmergencyAlert $alert): array
    {
        return [
            'id'            => $alert->id,
            'status'        => $alert->status,
            'triggered_at'  => $alert->triggered_at?->toISOString(),
            'dispatched_at' => $alert->dispatched_at?->toISOString(),
            'agency_label'  => $alert->agencyLabel(),
            'eta_minutes'   => $alert->eta_minutes,
            'on_scene_at'   => $alert->on_scene_at?->toISOString(),
            'outcome_label' => $alert->outcomeLabel(),
            'resolved_at'   => $alert->resolved_at?->toISOString(),
        ];
    }

    /** Reload relations and tell every admin view about the new step. */
    private function afterUpdate(EmergencyAlert $alert): EmergencyAlert
    {
        $alert->load('user', 'handler');
        $this->broadcastQuietly(new EmergencyAlertUpdated($alert));

        return $alert;
    }

    /**
     * Steps must happen in order. Two admins acting on the same alert at once
     * is the realistic case: the second one gets a clear message instead of
     * overwriting the first dispatch.
     *
     * @param string[] $allowed
     */
    private function ensureStatus(EmergencyAlert $alert, array $allowed, string $message): void
    {
        if (!in_array($alert->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private static function cleanName(?string $name): ?string
    {
        $name = $name !== null ? trim(preg_replace('/\s+/', ' ', $name)) : '';

        return $name !== '' ? $name : null;
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
        return EmergencyAlert::with('user', 'handler')
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
            'dispatched'  => EmergencyAlert::where('status', EmergencyAlert::STATUS_ACKNOWLEDGED)->count(),
            'resolved'    => EmergencyAlert::where('status', EmergencyAlert::STATUS_RESOLVED)->count(),
        ];
    }
}

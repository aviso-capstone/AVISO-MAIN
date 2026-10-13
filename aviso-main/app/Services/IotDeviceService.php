<?php

namespace App\Services;

use App\Models\EmergencyAlert;
use App\Models\IotDevice;
use App\Models\RiderEvent;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pairing, heartbeats and the Wi-Fi backup crash path for AVISO IoT units.
 *
 * Pairing: the rider asks the app for a 6-digit code, types it on the
 * device's setup page, and the device trades it for its own secret key.
 * The code proves the person holding the device is logged in as that rider.
 */
class IotDeviceService
{
    private const PAIRING_TTL_MINUTES = 10;

    public function __construct(
        private EmergencyAlertService $emergencyAlertService,
        private RiderEventService $riderEventService,
    ) {
        //
    }

    /**
     * @return array{code: string, expires_at: string}
     */
    public function createPairingCode(User $rider): array
    {
        do {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while (Cache::has($this->pairingKey($code)));

        $expiresAt = now()->addMinutes(self::PAIRING_TTL_MINUTES);
        Cache::put($this->pairingKey($code), $rider->id, $expiresAt);

        return ['code' => $code, 'expires_at' => $expiresAt->toISOString()];
    }

    /**
     * Exchanges a pairing code for the device's secret key. Returns the plain
     * key (shown to the device once, stored only as a hash) or null when the
     * code is wrong or expired. Re-pairing rotates the key.
     */
    public function claim(string $deviceUid, string $code, ?string $firmwareVersion): ?string
    {
        $riderId = Cache::pull($this->pairingKey($code));
        if (! $riderId) {
            return null;
        }

        $plainKey = Str::random(48);

        DB::transaction(function () use ($riderId, $deviceUid, $plainKey, $firmwareVersion) {
            // One unit per rider: a newly paired unit replaces the old one.
            IotDevice::forUser($riderId)->where('device_uid', '!=', $deviceUid)->update(['user_id' => null]);

            IotDevice::updateOrCreate(
                ['device_uid' => $deviceUid],
                [
                    'user_id' => $riderId,
                    'api_key_hash' => IotDevice::hashKey($plainKey),
                    'firmware_version' => $firmwareVersion,
                    'last_seen_at' => now(),
                ],
            );
        });

        return $plainKey;
    }

    public function findByCredentials(string $deviceUid, string $plainKey): ?IotDevice
    {
        $device = IotDevice::where('device_uid', $deviceUid)->first();

        if (! $device || ! $device->user_id || ! hash_equals($device->api_key_hash, IotDevice::hashKey($plainKey))) {
            return null;
        }

        return $device;
    }

    /**
     * @param  array{local_ip: string, rssi?: int|null, uptime_seconds?: int|null, firmware_version?: string|null, reset_reason?: string|null}  $data
     */
    public function recordHeartbeat(IotDevice $device, array $data): void
    {
        $device->update([
            'local_ip' => $data['local_ip'],
            'rssi' => $data['rssi'] ?? null,
            'uptime_seconds' => $data['uptime_seconds'] ?? null,
            'firmware_version' => $data['firmware_version'] ?? $device->firmware_version,
            'reset_reason' => $data['reset_reason'] ?? null,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * What the rider app needs to reach its unit on the hotspot.
     *
     * @return array<string, mixed>|null
     */
    public function deviceForRider(User $rider): ?array
    {
        $device = $rider->iotDevice;
        if (! $device) {
            return null;
        }

        return [
            'device_uid' => $device->device_uid,
            'local_ip' => $device->local_ip,
            'rssi' => $device->rssi,
            'firmware_version' => $device->firmware_version,
            'uptime_seconds' => $device->uptime_seconds,
            'reset_reason' => $device->reset_reason,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'online' => $device->isOnline(),
        ];
    }

    public function unpair(User $rider): void
    {
        IotDevice::forUser($rider->id)->update(['user_id' => null]);
    }

    /**
     * Backup crash path: the unit reports a crash itself because the phone
     * never confirmed it (app closed or crashed). The unit has no GPS, so the
     * rider's last known trip position is used. Returns null when the rider
     * has no known position at all.
     *
     * @param  array{event_uid: string, peak_g: float, peak_gyro_dps?: float|null, tilt_deg?: float|null}  $data
     */
    public function reportCrash(IotDevice $device, array $data): ?EmergencyAlert
    {
        $rider = $device->user;
        $trip = Trip::where('user_id', $rider->id)->latest('started_at')->first();

        if (! $trip || $trip->current_lat === null || $trip->current_lng === null) {
            return null;
        }

        $lat = (float) $trip->current_lat;
        $lng = (float) $trip->current_lng;

        $alert = $this->emergencyAlertService->triggerSos(
            $rider,
            $lat,
            $lng,
            null,
            $data['event_uid'],
            EmergencyAlert::SOURCE_IOT,
            EmergencyAlert::TRIGGER_CRASH_IOT,
        );

        // Also in the crash-detection log (same event_uid as the app's upload,
        // so it is stored once whichever arrives first).
        $this->riderEventService->record($rider, [
            'event_uid' => $data['event_uid'],
            'event_type' => RiderEvent::TYPE_CRASH,
            'latitude' => $lat,
            'longitude' => $lng,
            'acceleration_peak' => $data['peak_g'],
            'vertical_g' => $data['vertical_g'] ?? null,
            'horizontal_g' => $data['horizontal_g'] ?? null,
            'gyro_peak_dps' => $data['peak_gyro_dps'] ?? null,
            'tilt_deg' => $data['tilt_deg'] ?? null,
        ], RiderEvent::STATUS_ALERTED);

        return $alert;

    }

    private function pairingKey(string $code): string
    {
        return "iot-pairing:{$code}";
    }
}

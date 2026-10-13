<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmergencyAlert extends Model
{
    const STATUS_PENDING      = 'pending';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED     = 'resolved';

    const SOURCE_PHONE = 'phone';
    const SOURCE_IOT   = 'iot';

    /** How the SOS started (null on alerts older than this field). */
    const TRIGGER_MANUAL      = 'manual';
    const TRIGGER_CRASH_IOT   = 'crash_iot';
    const TRIGGER_CRASH_PHONE = 'crash_phone';

    const TRIGGER_TYPES = [
        self::TRIGGER_MANUAL,
        self::TRIGGER_CRASH_IOT,
        self::TRIGGER_CRASH_PHONE,
    ];

    protected $fillable = [
        'user_id',
        'event_uid',
        'source',
        'trigger_type',
        'rider_code',
        'latitude',
        'longitude',
        'barangay_code',
        'triggered_at',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'latitude'     => 'decimal:7',
        'longitude'    => 'decimal:7',
        'triggered_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Scope: alerts that still need attention — pending or acknowledged. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_ACKNOWLEDGED,
        ]);
    }

    public function scopeByRider(Builder $query, string $riderCode): Builder
    {
        return $query->where('rider_code', $riderCode);
    }

    /** A hazard counts as "just before the SOS" within this time and distance. */
    const HAZARD_BEFORE_SECONDS = 120;
    const HAZARD_BEFORE_METERS  = 100;

    /**
     * The road hazard this rider detected just before the SOS (the latest one
     * within 2 minutes before and 100 m of the SOS spot), or null. Hazards from
     * other riders or other days are never a cause of this rider's incident.
     *
     * @return array{haz_code: string, type: string, confidence: float, detected_at: ?string, meters_from_sos: int}|null
     */
    public function hazardJustBefore(): ?array
    {
        if (!$this->user_id || !$this->triggered_at) {
            return null;
        }

        $candidates = HazardLog::where('user_id', $this->user_id)
            ->whereBetween('detected_at', [
                $this->triggered_at->copy()->subSeconds(self::HAZARD_BEFORE_SECONDS),
                // Small allowance for the phone and server clocks differing.
                $this->triggered_at->copy()->addSeconds(10),
            ])
            ->latest('detected_at')
            ->get();

        foreach ($candidates as $hazard) {
            $meters = self::metersBetween(
                (float) $this->latitude, (float) $this->longitude,
                (float) $hazard->latitude, (float) $hazard->longitude,
            );
            if ($meters <= self::HAZARD_BEFORE_METERS) {
                return [
                    'haz_code'        => $hazard->haz_code,
                    'type'            => $hazard->type,
                    'confidence'      => (float) $hazard->confidence,
                    'detected_at'     => $hazard->detected_at?->toISOString(),
                    'meters_from_sos' => (int) round($meters),
                ];
            }
        }

        return null;
    }

    private static function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $toRad = M_PI / 180;
        $dLat  = ($lat2 - $lat1) * $toRad;
        $dLng  = ($lng2 - $lng1) * $toRad;
        $a     = sin($dLat / 2) ** 2 + cos($lat1 * $toRad) * cos($lat2 * $toRad) * sin($dLng / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A motion event classified by the IoT unit's BNO055 (bump, hard braking,
 * crash), located with the rider phone's GPS.
 */
class RiderEvent extends Model
{
    const TYPE_NORMAL = 'normal';

    const TYPE_HARD_BRAKING = 'hard_braking';

    const TYPE_ROAD_BUMP = 'road_bump';

    const TYPE_CRASH = 'crash';

    const TYPES = [
        self::TYPE_NORMAL,
        self::TYPE_HARD_BRAKING,
        self::TYPE_ROAD_BUMP,
        self::TYPE_CRASH,
    ];

    const STATUS_DETECTED = 'detected';

    const STATUS_ALERTED = 'alerted';

    const STATUS_ACKNOWLEDGED = 'acknowledged';

    const STATUS_RESOLVED = 'resolved';

    const SOURCE_IOT = 'iot';

    /** Date filters pick whole Philippine days (the database stores UTC). */
    const DAY_TIMEZONE = 'Asia/Manila';

    /** Readable names, also used in exports. */
    const TYPE_LABELS = [
        self::TYPE_NORMAL => 'Normal riding',
        self::TYPE_HARD_BRAKING => 'Hard braking',
        self::TYPE_ROAD_BUMP => 'Road bump',
        self::TYPE_CRASH => 'Crash',
    ];

    protected $fillable = [
        'event_uid',
        'source',
        'user_id',
        'rider_code',
        'trip_id',
        'event_type',
        'latitude',
        'longitude',
        'acceleration_peak',
        'vertical_g',
        'horizontal_g',
        'gyro_peak_dps',
        'tilt_deg',
        'barangay_code',
        'area',
        'detected_at',
        'status',
        'acknowledged_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'acceleration_peak' => 'decimal:4',
        'vertical_g' => 'decimal:3',
        'horizontal_g' => 'decimal:3',
        'gyro_peak_dps' => 'decimal:2',
        'tilt_deg' => 'decimal:2',
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    public function scopeDetectedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('detected_at', '>=', Carbon::parse($from, self::DAY_TIMEZONE)->startOfDay()->utc()))
            ->when($to, fn (Builder $q) => $q->where('detected_at', '<=', Carbon::parse($to, self::DAY_TIMEZONE)->endOfDay()->utc()));
    }
}

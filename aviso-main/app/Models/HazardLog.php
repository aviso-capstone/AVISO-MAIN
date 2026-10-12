<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HazardLog extends Model
{
    // ── Type constants ────────────────────────────────────────────────────────
    // Only physical road hazards are recorded. Traffic lights and signs are
    // live warnings on the rider's device and never reach this table.
    const TYPE_POTHOLE         = 'Pothole';
    const TYPE_ROAD_EXCAVATION = 'Road Excavation';
    const TYPE_ROAD_BARRIER    = 'Road Barrier';

    const TYPES = [
        self::TYPE_POTHOLE,
        self::TYPE_ROAD_EXCAVATION,
        self::TYPE_ROAD_BARRIER,
    ];

    // ── Hazard code: <PREFIX>-<YYYYMMDD>-<NNNN>, e.g. POT-20261001-0001 ───────
    // The date is the detection day in Philippine time; NNNN counts that
    // type's detections on that day.
    const CODE_PREFIXES = [
        self::TYPE_POTHOLE         => 'POT',
        self::TYPE_ROAD_EXCAVATION => 'EXC',
        self::TYPE_ROAD_BARRIER    => 'BAR',
    ];

    const CODE_TIMEZONE = 'Asia/Manila';

    // ── Status constants ──────────────────────────────────────────────────────
    const STATUS_ACTIVE   = 'active';
    const STATUS_RESOLVED = 'resolved';

    // ── Eloquent config ───────────────────────────────────────────────────────
    // `area` holds the barangay name and `barangay_code` its PSGC code, both
    // resolved from the coordinates by BarangayLocatorService.
    protected $fillable = [
        'haz_code',
        'type',
        'area',
        'barangay_code',
        'latitude',
        'longitude',
        'confidence',
        'distance',
        'gps_accuracy',
        'heading',
        'rider_code',
        'user_id',
        'trip_id',
        'status',
        'resolved_by',
        'resolved_at',
        'detected_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'confidence'  => 'decimal:2',
        'distance'    => 'decimal:2',
        'gps_accuracy' => 'decimal:2',
        'heading'     => 'decimal:2',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Scope: only active hazards */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Scope: filter by hazard type */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Scope: filter by PSGC barangay code */
    public function scopeByBarangay(Builder $query, string $barangayCode): Builder
    {
        return $query->where('barangay_code', $barangayCode);
    }

    /** Scope: only the three physical road hazard types */
    public function scopeRoadHazards(Builder $query): Builder
    {
        return $query->whereIn('type', [
            self::TYPE_POTHOLE,
            self::TYPE_ROAD_BARRIER,
            self::TYPE_ROAD_EXCAVATION,
        ]);
    }

    /**
     * Scope: detected between two calendar days (inclusive, e.g. Oct 12 – Oct 12
     * is that whole day). Days are Philippine days; the database stores UTC.
     */
    public function scopeDetectedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('detected_at', '>=', Carbon::parse($from, self::CODE_TIMEZONE)->startOfDay()->utc()))
            ->when($to, fn (Builder $q) => $q->where('detected_at', '<=', Carbon::parse($to, self::CODE_TIMEZONE)->endOfDay()->utc()));
    }

    /** Scope: search by haz_code or rider_code */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('haz_code',   'like', "%{$term}%")
              ->orWhere('rider_code', 'like', "%{$term}%");
        });
    }
}

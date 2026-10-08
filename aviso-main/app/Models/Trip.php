<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    // ── Status constants ──────────────────────────────────────────────────────
    const STATUS_ACTIVE = 'active';
    const STATUS_ENDED  = 'ended';

    // ── Eloquent config ───────────────────────────────────────────────────────
    protected $fillable = [
        'user_id',
        'rider_code',
        'start_lat',
        'start_lng',
        'current_lat',
        'current_lng',
        'end_lat',
        'end_lng',
        'status',
        'started_at',
        'ended_at',
        'route_points',
        'total_distance_km',
        'duration_minutes',
    ];

    protected $casts = [
        'start_lat'          => 'decimal:7',
        'start_lng'          => 'decimal:7',
        'current_lat'        => 'decimal:7',
        'current_lng'        => 'decimal:7',
        'end_lat'            => 'decimal:7',
        'end_lng'            => 'decimal:7',
        'started_at'         => 'datetime',
        'ended_at'           => 'datetime',
        'route_points'       => 'array',
        'total_distance_km'  => 'decimal:3',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hazardLogs(): HasMany
    {
        return $this->hasMany(HazardLog::class);
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Scope: only active (in-progress) trips */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Scope: filter by rider code */
    public function scopeByRider(Builder $query, string $riderCode): Builder
    {
        return $query->where('rider_code', $riderCode);
    }
}

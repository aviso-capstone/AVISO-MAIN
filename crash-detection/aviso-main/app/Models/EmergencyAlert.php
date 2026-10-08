<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmergencyAlert extends Model
{
    const STATUS_PENDING      = 'pending';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED     = 'resolved';

    protected $fillable = [
        'user_id',
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A rider's "hazard no longer there" report, with a photo of the spot as proof.
 */
class HazardReport extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_REJECTED  = 'rejected';

    /** How long a photo link given to the admin stays valid. */
    const PHOTO_URL_MINUTES = 60;

    protected $fillable = [
        'hazard_log_id',
        'user_id',
        'photo_path',
        'note',
        'latitude',
        'longitude',
        'distance_m',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'distance_m'  => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function hazard(): BelongsTo
    {
        return $this->belongsTo(HazardLog::class, 'hazard_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Short-lived link to the private photo (the bucket is never public). */
    public function getPhotoUrlAttribute(): ?string
    {
        try {
            return Storage::disk('s3')->temporaryUrl($this->photo_path, now()->addMinutes(self::PHOTO_URL_MINUTES));
        } catch (\Exception) {
            return null;
        }
    }
}

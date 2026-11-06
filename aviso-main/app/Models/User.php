<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Yajra\Address\HasAddress;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasAddress, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'username',
        'email',
        'contact_number',
        'address',
        'avatar_path',
        'password',
        'role',
        'is_active',
        'email_verified_at',
        'street',
        'barangay_id',
        'city_id',
        'province_id',
        'region_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Shown on the login page when a deactivated admin tries to get in. */
    public const DEACTIVATED_MESSAGE = 'This account has been deactivated. Please contact an AVISO administrator.';

    /** Shown when an admin who is already logged in gets deactivated. */
    public const DEACTIVATED_SESSION_MESSAGE = 'Your account has been deactivated. Please contact an AVISO administrator.';

    /**
     * Only an explicit false counts: right after a deploy the `is_active`
     * column may not exist yet (the web server starts before migrations run),
     * and that must not lock every admin out.
     */
    public function isDeactivated(): bool
    {
        return $this->is_active === false;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar_path) {
            return null;
        }
        try {
            return Storage::disk('s3')->temporaryUrl($this->avatar_path, now()->addDays(7));
        } catch (\Exception) {
            return null;
        }
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function emergencyAlerts(): HasMany
    {
        return $this->hasMany(EmergencyAlert::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function iotDevice(): HasOne
    {
        return $this->hasOne(IotDevice::class);
    }

    public function riderEvents(): HasMany
    {
        return $this->hasMany(RiderEvent::class);
    }

    public function hazardLogs(): HasMany
    {
        return $this->hasMany(HazardLog::class);
    }
}

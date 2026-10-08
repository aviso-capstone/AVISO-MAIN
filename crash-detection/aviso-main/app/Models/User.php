<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        ];
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

    public function hazardLogs(): HasMany
    {
        return $this->hasMany(HazardLog::class);
    }
}

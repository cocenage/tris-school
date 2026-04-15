<?php

namespace App\Models;

use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'telegram_photo_url',
        'telegram_avatar_path',

        'role',
        'status',

        'approved_at',
        'approved_by',
        'last_login_at',

        'birthday',
        'work_started_at',
        'dip',
        'is_active',

        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'birthday' => 'date',
            'work_started_at' => 'date',
            'dip' => 'boolean',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin' && $this->status === 'approved';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->telegram_avatar_path) {
            return Storage::disk('public')->url($this->telegram_avatar_path);
        }

        if ($this->telegram_photo_url) {
            return $this->telegram_photo_url;
        }

        return null;
    }

    public function dayOffRequests()
    {
        return $this->hasMany(\App\Models\DayOffRequest::class);
    }

    public function dayOffRequestDays()
    {
        return $this->hasMany(\App\Models\DayOffRequestDay::class);
    }

    public function vacationRequests()
    {
        return $this->hasMany(\App\Models\VacationRequest::class);
    }

    public function inventoryRequests()
    {
        return $this->hasMany(\App\Models\InventoryRequest::class);
    }
}
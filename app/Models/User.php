<?php

namespace App\Models;

use App\Services\TelegramUserNotificationService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'telegram_photo_url',
        'telegram_avatar_path',
        'telegram_write_access_granted_at',
        'telegram_last_auth_at',
        'telegram_login_source',
        'telegram_access_approved_notified_at',

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
            'telegram_write_access_granted_at' => 'datetime',
            'telegram_last_auth_at' => 'datetime',
            'telegram_access_approved_notified_at' => 'datetime',
            'birthday' => 'date',
            'work_started_at' => 'date',
            'dip' => 'boolean',
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function panelAccesses()
    {
        return $this->hasMany(UserPanelAccess::class);
    }

    public function hasPanelAccess(string $panel): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        return $this->panelAccesses()
            ->where('panel', $panel)
            ->where('can_access', true)
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        if ($this->role === 'admin') {
            return true;
        }

        return $this->hasPanelAccess($panel->getId());
    }

    public function calendarTypeAccesses()
    {
        return $this->hasMany(UserCalendarTypeAccess::class);
    }

    public function hasCalendarTypeAccess(string $type): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        return $this->calendarTypeAccesses()
            ->where('type', $type)
            ->where('can_view', true)
            ->exists();
    }

    public function allowedCalendarTypes(): array
    {
        if ($this->role === 'admin') {
            return [
                'workflow',
                'finance',
                'holiday',
                'peak',
                'vacation',
                'strike',
            ];
        }

        return $this->calendarTypeAccesses()
            ->where('can_view', true)
            ->pluck('type')
            ->values()
            ->all();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasTelegramWriteAccess(): bool
    {
        return !is_null($this->telegram_write_access_granted_at);
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
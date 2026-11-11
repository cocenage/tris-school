<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'telegram_access_requested_notified_at',

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

        'weekend_days',
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
            'weekend_days' => 'array',
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

    public function canViewAllStaffActivity(): bool
    {
        return $this->isApproved() && in_array($this->role, ['admin', 'supervisor'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        // Legacy per-user grants must never open the primary administration panel.
        if ($panel->getId() === 'admin') {
            return $this->role === 'admin';
        }

        if ($this->role === 'admin') {
            return true;
        }

        return $this->hasPanelAccess($panel->getId());
    }

    public function workScheduleVersions(): HasMany
    {
        return $this->hasMany(EmployeeWorkScheduleVersion::class);
    }

    public function calendarAdjustments(): HasMany
    {
        return $this->hasMany(EmployeeCalendarAdjustment::class);
    }

    public function calendarPermission(): HasOne
    {
        return $this->hasOne(UserCalendarPermission::class);
    }

    public function canViewAllEmployeeCalendars(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->role !== 'supervisor') {
            return false;
        }

        return (bool) $this->employeeCalendarPermission()?->can_view_all_calendars;
    }

    public function canEditEmployeeCalendars(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $permission = $this->employeeCalendarPermission();

        return $this->role === 'supervisor'
            && (bool) $permission?->can_view_all_calendars
            && (bool) $permission?->can_edit;
    }

    public function canExportEmployeeCalendars(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $permission = $this->employeeCalendarPermission();

        return $this->role === 'supervisor'
            && (bool) $permission?->can_view_all_calendars
            && (bool) $permission?->can_export;
    }

    private function employeeCalendarPermission(): ?UserCalendarPermission
    {
        return $this->relationLoaded('calendarPermission')
            ? $this->getRelation('calendarPermission')
            : $this->calendarPermission()->first();
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
                'tasks',
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
        return ! is_null($this->telegram_write_access_granted_at);
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
        return $this->hasMany(DayOffRequest::class);
    }

    public function dayOffRequestDays()
    {
        return $this->hasMany(DayOffRequestDay::class);
    }

    public function vacationRequestDays(): HasMany
    {
        return $this->hasMany(VacationRequestDay::class);
    }

    public function vacationRequests()
    {
        return $this->hasMany(VacationRequest::class);
    }

    public function inventoryRequests()
    {
        return $this->hasMany(InventoryRequest::class);
    }

    public function controlResponsesAsCleaner()
    {
        return $this->hasMany(ControlResponse::class, 'cleaner_id');
    }

    public function controlResponsesAsSupervisor()
    {
        return $this->hasMany(ControlResponse::class, 'supervisor_id');
    }

    public function apartmentAccessGrants()
    {
        return $this->hasMany(ApartmentUserAccess::class);
    }

    public function accessibleApartments()
    {
        return $this->belongsToMany(Apartment::class, 'apartment_user_access')
            ->withPivot(['granted_by', 'expires_at'])
            ->withTimestamps();
    }

    public function canManageTasks(): bool
    {
        return in_array($this->role, ['admin', 'supervisor'], true);
    }

    public function scopeActiveStaff($query)
    {
        return $query
            ->where('is_active', true)
            ->where('status', 'approved');
    }
}

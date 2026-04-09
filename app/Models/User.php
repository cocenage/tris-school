<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Panel;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'telegram_photo_url',

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

    public function dayOffRequests()
    {
        return $this->hasMany(\App\Models\DayOffRequest::class);
    }

    public function dayOffRequestDays()
    {
        return $this->hasMany(\App\Models\DayOffRequestDay::class);
    }
}

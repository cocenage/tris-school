<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApartmentUserAccess extends Model
{
    protected $table = 'apartment_user_access';

    protected $fillable = [
        'apartment_id',
        'user_id',
        'granted_by',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now(config('app.timezone', 'Europe/Rome'));

        return $query->where(function (Builder $active) use ($at): void {
            $active->whereNull('expires_at')->orWhere('expires_at', '>', $at);
        });
    }

    public function isActive(?Carbon $at = null): bool
    {
        return $this->expires_at === null || $this->expires_at->isAfter($at ?? now(config('app.timezone', 'Europe/Rome')));
    }
}

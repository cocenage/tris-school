<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Apartment extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (self $apartment): void {
            if ($apartment->accessGrants()->exists()) {
                throw new \RuntimeException('Нельзя удалить квартиру с выданными доступами: сначала отзовите доступы.');
            }

            $apartment->informationAttachments()->get()->each->delete();
        });
    }

    protected $fillable = [
        'name',
        'code',
        'address',
        'image',
        'notes',
        'is_active',
        'information_status',
        'information_updated_at',
        'information_updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'information_updated_at' => 'datetime',
    ];

    public function controlResponses(): HasMany
    {
        return $this->hasMany(ControlResponse::class);
    }

    public function controlResponseDrafts(): HasMany
    {
        return $this->hasMany(ControlResponseDraft::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(ApartmentUserAccess::class);
    }

    public function authorizedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'apartment_user_access')
            ->withPivot(['granted_by', 'expires_at'])
            ->withTimestamps();
    }

    public function informationSections(): HasMany
    {
        return $this->hasMany(ApartmentInformationSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function informationAttachments(): HasMany
    {
        return $this->hasMany(ApartmentInformationAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function informationUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'information_updated_by');
    }
}

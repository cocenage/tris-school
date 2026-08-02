<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApartmentInformationSection extends Model
{
    use HasFactory;

    public const TYPES = [
        'general', 'access', 'keys', 'cleaning', 'laundry', 'supplies',
        'appliances', 'wifi', 'warnings', 'contacts', 'custom',
    ];

    protected $fillable = [
        'apartment_id',
        'type',
        'title',
        'content',
        'sort_order',
        'is_visible',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $section): void {
            if (auth()->check()) {
                $section->created_by ??= auth()->id();
                $section->updated_by ??= auth()->id();
            }
        });

        static::updating(function (self $section): void {
            if (auth()->check()) {
                $section->updated_by = auth()->id();
            }
        });

        static::saved(fn (self $section) => $section->touchApartmentInformation());
        static::deleted(fn (self $section) => $section->touchApartmentInformation());
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApartmentInformationAttachment::class, 'information_section_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    protected function touchApartmentInformation(): void
    {
        if ($this->apartment_id) {
            Apartment::query()->whereKey($this->apartment_id)->update([
                'information_updated_at' => now(),
                'information_updated_by' => auth()->id(),
            ]);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ApartmentInformationAttachment extends Model
{
    use HasFactory;

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    protected $fillable = [
        'apartment_id',
        'information_section_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'caption',
        'sort_order',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attachment): void {
            if (! $attachment->information_section_id) {
                return;
            }

            $section = ApartmentInformationSection::query()->find($attachment->information_section_id);

            if (! $section || (int) $section->apartment_id !== (int) $attachment->apartment_id) {
                throw ValidationException::withMessages([
                    'information_section_id' => 'Раздел должен принадлежать той же квартире, что и вложение.',
                ]);
            }
        });

        static::creating(function (self $attachment): void {
            if (auth()->check()) {
                $attachment->uploaded_by ??= auth()->id();
            }
        });

        static::deleted(function (self $attachment): void {
            if ($attachment->disk && $attachment->path) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            if ($attachment->apartment_id) {
                Apartment::query()->whereKey($attachment->apartment_id)->update([
                    'information_updated_at' => now(),
                    'information_updated_by' => auth()->id(),
                ]);
            }
        });

        static::saved(function (self $attachment): void {
            if ($attachment->apartment_id) {
                Apartment::query()->whereKey($attachment->apartment_id)->update([
                    'information_updated_at' => now(),
                    'information_updated_by' => auth()->id(),
                ]);
            }
        });
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ApartmentInformationSection::class, 'information_section_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApartmentTelegramImport extends Model
{
    protected $fillable = [
        'apartment_id',
        'original_name',
        'file_size',
        'sha256',
        'status',
        'message_count',
        'photo_count',
        'document_count',
        'skipped_count',
        'imported_by',
        'imported_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'message_count' => 'integer',
        'photo_count' => 'integer',
        'document_count' => 'integer',
        'skipped_count' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}

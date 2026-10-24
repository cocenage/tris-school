<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coaching extends Model
{
    protected $fillable = [
        'user_id',
        'supervisor_id',
        'control_id',
        'apartment_id',
        'cleaning_date',
        'inspection_date',
        'is_assigned',
        'previous_cleaner',
        'control_response_ids',
        'reason',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'cleaning_date' => 'date',
            'inspection_date' => 'date',
            'is_assigned' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function getCleanerIdAttribute(): ?int
    {
        return isset($this->attributes['user_id']) ? (int) $this->attributes['user_id'] : null;
    }

    public function setCleanerIdAttribute(?int $value): void
    {
        $this->attributes['user_id'] = $value;
    }

    public function getConductedDateAttribute(): mixed
    {
        return $this->inspection_date;
    }

    public function setConductedDateAttribute(mixed $value): void
    {
        $this->inspection_date = $value;
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->reason;
    }

    public function setDescriptionAttribute(?string $value): void
    {
        $this->reason = $value;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AntigocciaReport extends Model
{
    protected $fillable = [
        'apartment_id', 'user_id', 'cleaner_name', 'cleaning_date',
        'answers', 'photos', 'remaining', 'submission_uuid',
    ];

    protected $casts = [
        'cleaning_date' => 'date',
        'answers' => 'array',
        'photos' => 'array',
        'remaining' => 'boolean',
    ];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

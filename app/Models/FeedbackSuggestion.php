<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackSuggestion extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'comment',
        'attachments',
        'status',
        'admin_comment',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'user_name',
    ];

    public function getUserNameAttribute(): string
    {
        return $this->user?->name ?? '—';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
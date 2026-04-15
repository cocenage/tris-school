<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayOffRequestDay extends Model
{
    protected $fillable = [
        'day_off_request_id',
        'user_id',
        'date',
        'status',
        'admin_comment',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(DayOffRequest::class, 'day_off_request_id');
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
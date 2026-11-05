<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCalendarPermission extends Model
{
    protected $fillable = [
        'user_id',
        'can_view_all_calendars',
        'can_edit',
        'can_export',
    ];

    protected function casts(): array
    {
        return [
            'can_view_all_calendars' => 'boolean',
            'can_edit' => 'boolean',
            'can_export' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

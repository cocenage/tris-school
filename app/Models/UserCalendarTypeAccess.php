<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCalendarTypeAccess extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'can_view',
    ];

    protected $casts = [
        'can_view' => 'boolean',
    ];
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPanelAccess extends Model
{
    protected $fillable = [
        'user_id',
        'panel',
        'can_access',
    ];

    protected $casts = [
        'can_access' => 'boolean',
    ];
}

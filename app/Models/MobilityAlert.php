<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilityAlert extends Model
{
    protected $fillable = [
        'source',
        'title',
        'description',
        'url',
        'type',
        'risk',
        'district',
        'starts_at',
        'ends_at',
        'sent_at',
        'external_hash',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'sent_at' => 'datetime',
    ];
}
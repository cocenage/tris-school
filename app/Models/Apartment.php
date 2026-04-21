<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Apartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'image',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function controlResponses()
    {
        return $this->hasMany(ControlResponse::class);
    }

    public function controlResponseDrafts()
    {
        return $this->hasMany(ControlResponseDraft::class);
    }
}
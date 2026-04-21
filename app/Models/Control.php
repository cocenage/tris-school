<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Control extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'image',
        'main',
        'is_active',
    ];

    protected $casts = [
        'main' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function responses()
    {
        return $this->hasMany(ControlResponse::class);
    }

    public function drafts()
    {
        return $this->hasMany(ControlResponseDraft::class);
    }
}
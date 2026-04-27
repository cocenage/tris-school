<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstructionCategory extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'icon',
        'emoji',
        'color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function instructions(): HasMany
    {
        return $this->hasMany(Instruction::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Instruction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'instruction_category_id',
        'author_id',
        'title',
        'slug',
        'short_description',
        'cover_image',
        'icon',
        'emoji',
        'color',
        'blocks',
        'status',
        'is_featured',
        'is_public',
        'views_count',
        'sort_order',
        'published_at',
    ];

    protected $casts = [
        'blocks' => 'array',
        'is_featured' => 'boolean',
        'is_public' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(InstructionCategory::class, 'instruction_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }
}
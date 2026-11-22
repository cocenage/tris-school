<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AntigocciaApplication extends Model
{
    protected $fillable = ['apartment_id', 'applied_on', 'created_by', 'notes'];

    protected $casts = ['applied_on' => 'date'];

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

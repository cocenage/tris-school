<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacationRequestDay extends Model
{
    protected $fillable = [
        'vacation_request_id',
        'user_id',
        'date',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function vacationRequest(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function request(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class, 'vacation_request_id');
    }
}
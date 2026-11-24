<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryQuestionDecision extends Model
{
    protected $fillable = [
        'salary_question_id', 'reviewer_id', 'version', 'status', 'comment',
        'source', 'delivery_status', 'telegram_message_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'telegram_message_id' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(SalaryQuestion::class, 'salary_question_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class StaffTextQuestionDecision extends Model
{
    protected const QUESTION_MODEL = SalaryQuestion::class;
    protected const QUESTION_KEY = 'salary_question_id';

    protected $fillable = [
        'salary_question_id', 'schedule_question_id', 'feedback_suggestion_id', 'reviewer_id', 'version', 'status', 'comment',
        'source', 'delivery_status', 'telegram_message_id', 'card_sync_status', 'card_sync_attempts',
    ];

    protected $casts = [
        'version' => 'integer',
        'card_sync_attempts' => 'integer',
        'telegram_message_id' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(static::QUESTION_MODEL, static::QUESTION_KEY);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}

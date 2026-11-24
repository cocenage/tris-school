<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryQuestion extends Model
{
    public const DECISION_STATUSES = ['in_progress', 'approved', 'rejected'];

    public const STATUS_LABELS = [
        'pending' => 'Ожидает решения',
        'in_progress' => 'В работе',
        'approved' => 'Одобрено',
        'rejected' => 'Отказано',
        'reviewed' => 'Рассмотрено',
        'closed' => 'Закрыто',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'comment',
        'attachments',
        'status',
        'admin_comment',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'reviewed_at' => 'datetime',
        'answered_at' => 'datetime',
        'answer_seen_at' => 'datetime',
        'decision_version' => 'integer',
    ];

    protected $appends = [
        'user_name',
    ];

    public function getUserNameAttribute(): string
    {
        return $this->user?->name ?? '—';
    }

    public function usesDecisionWorkflow(): bool
    {
        return filled($this->telegram_source_message_id) || (int) $this->decision_version > 0;
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SalaryQuestionDecision::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
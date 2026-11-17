<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramScheduledMessageDelivery extends Model
{
    public const OKS_TEST = 'oks_live_test';

    protected $fillable = [
        'scheduled_message_id',
        'trigger_delivery_id',
        'criteria_hash',
        'sequence_context',
        'render_hash',
        'control_type',
        'bot_type',
        'chat_id',
        'message_thread_id',
        'scheduled_for',
        'sent_at',
        'sent_at_utc',
        'telegram_message_id',
        'status',
        'failure_reason',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'telegram_message_id' => 'integer',
        'sequence_context' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(self::OKS_TEST, fn (Builder $query): Builder => $query->where($query->qualifyColumn('status'), '!=', self::OKS_TEST));
    }

    public function scopeWithOksTests(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::OKS_TEST);
    }

    public function isOksTest(): bool
    {
        return $this->status === self::OKS_TEST;
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TelegramScheduledMessageResponse::class, 'delivery_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(TelegramScheduledControlFollowup::class, 'delivery_id');
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramScheduledMessage::class, 'scheduled_message_id');
    }

    public function triggerDelivery(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trigger_delivery_id');
    }
}

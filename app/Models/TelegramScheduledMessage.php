<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TelegramScheduledMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'control_type',
        'telegram_chat_record_id',
        'telegram_topic_record_id',
        'message',
        'send_time',
        'weekdays',
        'enabled',
    ];

    protected $casts = [
        'weekdays' => 'array',
        'enabled' => 'boolean',
    ];

    public function telegramChat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_record_id');
    }

    public function telegramTopic(): BelongsTo
    {
        return $this->belongsTo(TelegramTopic::class, 'telegram_topic_record_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramScheduledMessageDelivery::class, 'scheduled_message_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function weekdaysLabel(): string
    {
        $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

        return collect($this->weekdays ?? [])
            ->map(fn (int|string $day): ?string => $labels[(int) $day] ?? null)
            ->filter()
            ->implode(', ');
    }
}

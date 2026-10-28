<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
        'followup_repeat_enabled',
        'followup_interval_minutes',
        'followup_max_repeats',
        'followup_reminder_enabled',
        'followup_reminder_minutes',
        'followup_cutoff',
    ];

    protected $casts = [
        'weekdays' => 'array',
        'enabled' => 'boolean',
        'followup_repeat_enabled' => 'boolean',
        'followup_reminder_enabled' => 'boolean',
        'followup_interval_minutes' => 'integer',
        'followup_max_repeats' => 'integer',
        'followup_reminder_minutes' => 'integer',
    ];

    private const REVISION_FIELDS = [
        'name', 'control_type', 'send_time', 'weekdays', 'enabled', 'deleted_at',
        'telegram_chat_record_id', 'telegram_topic_record_id',
    ];

    public function save(array $options = []): bool
    {
        $connection = $this->getConnection();
        $track = ! $this->exists || $this->isDirty(self::REVISION_FIELDS);
        if (! $track || ! $connection->getSchemaBuilder()->hasTable('telegram_scheduled_message_revisions')) {
            return parent::save($options);
        }

        return $connection->transaction(function () use ($options): bool {
            if ($this->exists && ! $this->revisionQuery()->exists()) {
                $old = $this->newQuery()->withTrashed()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $old->writeRevision(true);
            }
            $saved = parent::save($options);
            if ($saved) {
                $this->writeRevision(false);
            }

            return $saved;
        });
    }

    public function delete()
    {
        $connection = $this->getConnection();
        if (! $this->exists || ! $connection->getSchemaBuilder()->hasTable('telegram_scheduled_message_revisions')) {
            return parent::delete();
        }

        return $connection->transaction(function () {
            if (! $this->revisionQuery()->exists()) {
                $this->writeRevision(true);
            }
            $deleted = parent::delete();
            if ($deleted) {
                $this->writeRevision(false);
            }

            return $deleted;
        });
    }

    private function revisionQuery()
    {
        return $this->getConnection()->table('telegram_scheduled_message_revisions')
            ->where('scheduled_message_id', $this->getKey());
    }

    private function writeRevision(bool $baseline): void
    {
        $this->getConnection()->table('telegram_scheduled_message_revisions')->insert([
            'scheduled_message_id' => $this->getKey(),
            'effective_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'baseline' => $baseline,
            'control_type' => $this->control_type,
            'send_time' => $this->send_time,
            'weekdays' => json_encode($this->weekdays ?? [], JSON_THROW_ON_ERROR),
            'enabled' => (bool) $this->enabled,
            'is_deleted' => $this->deleted_at !== null,
            'name' => $this->name,
            'telegram_chat_record_id' => $this->telegram_chat_record_id,
            'telegram_topic_record_id' => $this->telegram_topic_record_id,
        ]);
    }

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

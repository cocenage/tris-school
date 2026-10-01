<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeEntity extends Model
{
    public const TYPES = [
        'system' => 'Система', 'bot' => 'Бот', 'feature' => 'Функция', 'process' => 'Процесс',
        'integration' => 'Интеграция', 'instruction' => 'Инструкция',
        'telegram_chat' => 'Telegram-чат', 'telegram_topic' => 'Telegram-тема', 'service' => 'Сервис',
    ];

    public const STATUSES = [
        'active' => 'Активно', 'in_progress' => 'В работе', 'planned' => 'Запланировано',
        'paused' => 'Приостановлено', 'deprecated' => 'Устарело',
    ];

    public const GROUPS = ['telegram' => 'Telegram', 'operations' => 'Операции', 'automation' => 'Автоматизация', 'knowledge' => 'Знания'];

    public const LINKED_TYPES = [
        Instruction::class => 'Инструкция', Apartment::class => 'Квартира',
        TelegramChat::class => 'Telegram-чат', TelegramTopic::class => 'Telegram-тема',
        TelegramScheduledMessage::class => 'Плановое сообщение',
    ];

    protected $fillable = ['title', 'slug', 'type', 'status', 'summary', 'body', 'icon', 'group_key', 'sort_order', 'linked_type', 'linked_id'];

    protected $casts = ['sort_order' => 'integer', 'linked_id' => 'integer'];

    protected $attributes = ['type' => 'feature', 'status' => 'planned', 'group_key' => 'knowledge', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $entity): void {
            if (! isset(self::TYPES[$entity->type], self::STATUSES[$entity->status])) {
                throw ValidationException::withMessages(['type' => 'Выберите поддерживаемый тип и статус.']);
            }
            if (blank($entity->slug)) {
                $base = Str::slug($entity->title) ?: 'entity';
                $entity->slug = $base;
                $suffix = 2;
                while (static::query()->where('slug', $entity->slug)->when($entity->exists, fn ($query) => $query->whereKeyNot($entity->id))->exists()) {
                    $entity->slug = $base.'-'.$suffix++;
                }
            }
            if (filled($entity->linked_type) !== filled($entity->linked_id)) {
                throw ValidationException::withMessages(['linked_id' => 'Укажите тип и ID связанного объекта вместе.']);
            }
            if (filled($entity->linked_type)) {
                if (! array_key_exists($entity->linked_type, self::LINKED_TYPES)) {
                    throw ValidationException::withMessages(['linked_type' => 'Этот тип объекта не поддерживается.']);
                }
                $linkedClass = $entity->linked_type;
                if (! $linkedClass::query()->whereKey($entity->linked_id)->exists()) {
                    throw ValidationException::withMessages(['linked_id' => 'Связанный объект не найден.']);
                }
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function linked(): MorphTo
    {
        return $this->morphTo();
    }

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(KnowledgeEntityRelation::class, 'source_entity_id')->orderBy('sort_order')->orderBy('id');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(KnowledgeEntityRelation::class, 'target_entity_id')->orderBy('sort_order')->orderBy('id');
    }

    public function renderedBody(): string
    {
        return Str::markdown($this->body ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 30]);
    }
}

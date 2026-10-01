<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class KnowledgeEntityRelation extends Model
{
    public const TYPES = [
        'related_to' => 'Связано с', 'uses' => 'Использует', 'used_by' => 'Используется',
        'produces' => 'Создаёт', 'receives_from' => 'Получает от', 'sends_to' => 'Отправляет в',
        'documents' => 'Описывает', 'belongs_to' => 'Входит в', 'depends_on' => 'Зависит от', 'handled_by' => 'Обрабатывается',
    ];

    protected $fillable = ['source_entity_id', 'target_entity_id', 'relation_type', 'note', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    protected $attributes = ['relation_type' => 'related_to', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $relation): void {
            if (! isset(self::TYPES[$relation->relation_type])) {
                throw ValidationException::withMessages(['relation_type' => 'Выберите поддерживаемый тип связи.']);
            }
            if (static::query()->where('source_entity_id', $relation->source_entity_id)
                ->where('target_entity_id', $relation->target_entity_id)->where('relation_type', $relation->relation_type)
                ->when($relation->exists, fn ($query) => $query->whereKeyNot($relation->id))->exists()) {
                throw ValidationException::withMessages(['relation_type' => 'Такая связь уже существует.']);
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'source_entity_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'target_entity_id');
    }
}

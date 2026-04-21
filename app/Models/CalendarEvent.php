<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CalendarEvent extends Model
{
    protected $fillable = [
        'type',
        'title',
        'description',
        'start_date',
        'end_date',
        'repeat_type',
        'repeat_until',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'repeat_until' => 'date',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public const TYPE_WORKFLOW = 'workflow';
    public const TYPE_FINANCE = 'finance';
    public const TYPE_HOLIDAY = 'holiday';
    public const TYPE_PEAK = 'peak';
    public const TYPE_STRIKE = 'strike';

    public const REPEAT_NONE = 'none';
    public const REPEAT_YEARLY = 'yearly';
    public const REPEAT_MONTHLY = 'monthly';
    public const REPEAT_WEEKLY = 'weekly';

    public static function typeOptions(): array
    {
        return [
            self::TYPE_WORKFLOW => 'Рабочий процесс',
            self::TYPE_FINANCE => 'Финансы',
            self::TYPE_HOLIDAY => 'Праздник',
            self::TYPE_PEAK => 'Пик загрузки',
            self::TYPE_STRIKE => 'Забастовки',
        ];
    }

    public static function repeatOptions(): array
    {
        return [
            self::REPEAT_NONE => 'Не повторять',
            self::REPEAT_YEARLY => 'Ежегодно',
            self::REPEAT_MONTHLY => 'Ежемесячно',
            self::REPEAT_WEEKLY => 'Еженедельно',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getResolvedEndDateAttribute(): Carbon
    {
        return $this->end_date ?: $this->start_date;
    }

    public function isRange(): bool
    {
        return !is_null($this->end_date) && !$this->end_date->isSameDay($this->start_date);
    }

    public function getTypeLabelAttribute(): string
    {
        return static::typeOptions()[$this->type] ?? $this->type;
    }

    public function getRepeatLabelAttribute(): string
    {
        return static::repeatOptions()[$this->repeat_type] ?? $this->repeat_type;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
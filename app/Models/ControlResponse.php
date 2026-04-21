<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ControlResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'control_id',
        'supervisor_id',
        'cleaner_id',
        'apartment_id',

        'is_assigned',
        'previous_cleaner',
        'cleaning_date',
        'inspection_date',

        'comment',
        'responses',
        'schema_snapshot',

        'total_points',
        'max_points',
        'score_percent',
        'has_critical_failure',
        'result_zone',

        'status',
        'sent_at',
    ];

    protected $casts = [
        'responses' => 'array',
        'schema_snapshot' => 'array',
        'is_assigned' => 'boolean',
        'cleaning_date' => 'date',
        'inspection_date' => 'date',
        'has_critical_failure' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function control()
    {
        return $this->belongsTo(Control::class);
    }

    public function apartment()
    {
        return $this->belongsTo(Apartment::class);
    }

    public function cleaner()
    {
        return $this->belongsTo(User::class, 'cleaner_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function getResultZoneLabelAttribute(): string
    {
        return match ($this->result_zone) {
            'green' => 'Зелёная зона',
            'yellow' => 'Жёлтая зона',
            'red' => 'Красная зона',
            default => 'Без оценки',
        };
    }

    public static function calculateScores(array $schema, array $responses): array
    {
        $totalPoints = 0;
        $maxPoints = 0;
        $hasCriticalFailure = false;

        foreach ($schema as $roomIndex => $room) {
            $roomOptional = (bool) ($room['is_optional'] ?? false);
            $items = Arr::get($room, 'items', []);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $questionIndex => $question) {
                $questionOptional = (bool) ($question['is_optional'] ?? false);

                if ($roomOptional || $questionOptional) {
                    // необязательные не влияют на обязательный максимум
                }

                $answerType = (string) ($question['answer_type'] ?? 'options');
                $selected = data_get($responses, "{$roomIndex}.{$questionIndex}.selected");
                $options = $question['answer_options_scored'] ?? [];
                $options = is_array($options) ? $options : [];

                if (in_array($answerType, ['options', 'both'], true) && count($options)) {
                    $maxForQuestion = 0;
                    $selectedOption = null;

                    foreach ($options as $option) {
                        $points = (int) ($option['points'] ?? 0);
                        if ($points > $maxForQuestion) {
                            $maxForQuestion = $points;
                        }

                        if ((string) ($option['value'] ?? '') === (string) $selected) {
                            $selectedOption = $option;
                        }
                    }

                    $maxPoints += $maxForQuestion;

                    if ($selectedOption) {
                        $selectedPoints = (int) ($selectedOption['points'] ?? 0);
                        $totalPoints += $selectedPoints;

                        $isCritical = (bool) ($question['is_critical'] ?? false);
                        $isPositive = (bool) ($selectedOption['is_positive'] ?? false);

                        if ($isCritical && ! $isPositive) {
                            $hasCriticalFailure = true;
                        }
                    }
                }
            }
        }

        $scorePercent = $maxPoints > 0
            ? (int) round(($totalPoints / $maxPoints) * 100)
            : 0;

        $resultZone = self::resolveResultZone($scorePercent, $hasCriticalFailure);

        return [
            'total_points' => $totalPoints,
            'max_points' => $maxPoints,
            'score_percent' => $scorePercent,
            'has_critical_failure' => $hasCriticalFailure,
            'result_zone' => $resultZone,
        ];
    }

    public static function resolveResultZone(int $scorePercent, bool $hasCriticalFailure): string
    {
        if ($scorePercent < 70) {
            return 'red';
        }

        if ($hasCriticalFailure) {
            return 'yellow';
        }

        if ($scorePercent >= 90) {
            return 'green';
        }

        return 'yellow';
    }
}
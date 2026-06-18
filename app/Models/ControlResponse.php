<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
        'penalty_points',
        'errors_count',
        'has_critical_failure',
        'result_zone',
        'result_zone_reason',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'responses' => 'array',
        'schema_snapshot' => 'array',
        'is_assigned' => 'boolean',
        'cleaning_date' => 'date',
        'inspection_date' => 'date',
        'total_points' => 'integer',
        'max_points' => 'integer',
        'score_percent' => 'integer',
        'penalty_points' => 'integer',
        'errors_count' => 'integer',
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

    public function getResultZoneColorAttribute(): string
    {
        return match ($this->result_zone) {
            'green' => 'success',
            'yellow' => 'warning',
            'red' => 'danger',
            default => 'gray',
        };
    }

    public static function calculateScores(array $schema, array $responses): array
    {
        $analysis = self::analyzeAnswers($schema, $responses);

        $scorePercent = $analysis['max_points'] > 0
            ? (int) round(($analysis['total_points'] / $analysis['max_points']) * 100)
            : 0;

        $zoneData = self::resolveResultZoneData(
            penaltyPoints: $analysis['penalty_points'],
            hasCriticalFailure: $analysis['has_critical_failure'],
        );

        return [
            'total_points' => $analysis['total_points'],
            'max_points' => $analysis['max_points'],
            'score_percent' => $scorePercent,
            'penalty_points' => $analysis['penalty_points'],
            'errors_count' => count($analysis['errors']),
            'has_critical_failure' => $analysis['has_critical_failure'],
            'result_zone' => $zoneData['zone'],
            'result_zone_reason' => $zoneData['reason'],
        ];
    }

    public static function analyzeAnswers(array $schema, array $responses): array
    {
        $totalPoints = 0;
        $maxPoints = 0;
        $penaltyPoints = 0;
        $hasCriticalFailure = false;
        $errors = [];

        foreach ($schema as $roomIndex => $room) {
            $roomTitle = (string) ($room['title'] ?? ('Комната ' . ((int) $roomIndex + 1)));
            $roomOptional = (bool) ($room['is_optional'] ?? false);
            $items = Arr::get($room, 'items', []);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $questionIndex => $question) {
                if ($roomOptional || (bool) ($question['is_optional'] ?? false)) {
                    continue;
                }

                $answerType = (string) ($question['answer_type'] ?? 'options');

                if (! in_array($answerType, ['options', 'both'], true)) {
                    continue;
                }

                $options = $question['answer_options_scored'] ?? [];

                if (! is_array($options) || empty($options)) {
                    continue;
                }

                $answer = data_get($responses, "{$roomIndex}.{$questionIndex}", []);
                $selected = trim((string) ($answer['selected'] ?? ''));
                $custom = trim((string) ($answer['custom'] ?? ''));

                [$maxForQuestion, $selectedPoints] = self::resolveSelectedPoints($options, $selected);

                if ($maxForQuestion <= 0) {
                    continue;
                }

                $maxPoints += $maxForQuestion;
                $totalPoints += $selectedPoints;

                $questionPenalty = max(0, $maxForQuestion - $selectedPoints);

                if ($questionPenalty <= 0) {
                    continue;
                }

                $penaltyPoints += $questionPenalty;

                $isCritical = self::isCriticalQuestion($roomTitle, (int) $questionIndex, $question);

                if ($isCritical) {
                    $hasCriticalFailure = true;
                }

                $errors[] = [
                    'room_title' => $roomTitle,
                    'question' => (string) ($question['question'] ?? 'Вопрос'),
                    'selected_label' => self::resolveAnswerText($question, $selected, $custom),
                    'custom' => $custom,
                    'points' => $selectedPoints,
                    'max_points' => $maxForQuestion,
                    'penalty_points' => $questionPenalty,
                    'is_critical' => $isCritical,
                    'media' => is_array($answer['media'] ?? null) ? $answer['media'] : [],
                ];
            }
        }

        return [
            'total_points' => $totalPoints,
            'max_points' => $maxPoints,
            'penalty_points' => $penaltyPoints,
            'has_critical_failure' => $hasCriticalFailure,
            'errors' => $errors,
        ];
    }

public static function resolveResultZoneData(int $penaltyPoints, bool $hasCriticalFailure): array
{
    if ($penaltyPoints <= 0) {
        return [
            'zone' => 'green',
            'reason' => 'Ошибок нет',
        ];
    }

    if ($hasCriticalFailure) {
        return [
            'zone' => 'red',
            'reason' => 'Критическая ошибка в первых двух вопросах блока “Спальные места”',
        ];
    }

    if ($penaltyPoints >= 8) {
        return [
            'zone' => 'red',
            'reason' => 'Набрано 8 или больше штрафных баллов',
        ];
    }

    return [
        'zone' => 'yellow',
        'reason' => 'Есть ошибки, но меньше 8 штрафных баллов',
    ];
}
    public static function resolveResultZone(int $penaltyPoints, bool $hasCriticalFailure): string
    {
        return self::resolveResultZoneData($penaltyPoints, $hasCriticalFailure)['zone'];
    }

    protected static function resolveSelectedPoints(array $options, string $selected): array
    {
        $maxForQuestion = 0;
        $selectedPoints = 0;

        foreach ($options as $optionIndex => $option) {
            $points = (int) ($option['points'] ?? 0);
            $maxForQuestion = max($maxForQuestion, $points);

            $optionValue = trim((string) ($option['value'] ?? $option['label'] ?? ''));
            $optionLabel = trim((string) ($option['label'] ?? ''));
            $legacyValue = 'option_' . $optionIndex;

            if (
                $selected !== ''
                && (
                    $selected === $optionValue
                    || $selected === $optionLabel
                    || $selected === $legacyValue
                )
            ) {
                $selectedPoints = $points;
            }
        }

        return [$maxForQuestion, $selectedPoints];
    }

    protected static function isCriticalQuestion(string $roomTitle, int $questionIndex, array $question): bool
    {
        if ((bool) ($question['is_red_zone_question'] ?? false)) {
            return true;
        }

        return self::isSleepingPlacesCriticalQuestion($roomTitle, $questionIndex);
    }

    protected static function isSleepingPlacesCriticalQuestion(string $roomTitle, int $questionIndex): bool
    {
        $normalizedTitle = Str::of($roomTitle)
            ->lower()
            ->replace('ё', 'е')
            ->toString();

        return str_contains($normalizedTitle, 'спальные места')
            && in_array($questionIndex, [0, 1], true);
    }

    public static function resolveAnswerText(array $question, string $selected, string $custom): string
    {
        $label = $selected;

        foreach (($question['answer_options_scored'] ?? []) as $optIndex => $opt) {
            $value = trim((string) ($opt['value'] ?? ($opt['label'] ?? ('option_' . $optIndex))));
            $optionLabel = trim((string) ($opt['label'] ?? ''));

            if (
                $selected !== ''
                && (
                    $selected === $value
                    || $selected === $optionLabel
                    || $selected === 'option_' . $optIndex
                )
            ) {
                $label = $optionLabel;
                break;
            }
        }

        if ($label !== '' && $custom !== '') {
            return $label . ' / ' . $custom;
        }

        return $label !== '' ? $label : ($custom !== '' ? $custom : '—');
    }

    public function rewardPointEvents(): MorphMany
    {
        return $this->morphMany(RewardProgramPointEvent::class, 'source');
    }
}
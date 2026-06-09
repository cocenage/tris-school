<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function user()
    {
        return $this->belongsTo(User::class);
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

        return [
            'total_points' => $analysis['total_points'],
            'max_points' => $analysis['max_points'],
            'score_percent' => $scorePercent,
            'has_critical_failure' => $analysis['has_critical_failure'],
            'result_zone' => self::resolveResultZone(
                $analysis['penalty_points'],
                $analysis['has_critical_failure']
            ),
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
                $questionOptional = (bool) ($question['is_optional'] ?? false);

                if ($roomOptional || $questionOptional) {
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

                [$maxForQuestion, $selectedOption, $selectedPoints] = self::resolveSelectedOption(
                    $options,
                    $selected
                );

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

                $isSleepingCritical = self::isSleepingPlacesCriticalQuestion(
                    $roomTitle,
                    (int) $questionIndex
                );

                if ($isSleepingCritical) {
                    $hasCriticalFailure = true;
                }

                $errors[] = [
                    'room_index' => (int) $roomIndex,
                    'question_index' => (int) $questionIndex,
                    'room_title' => $roomTitle,
                    'question' => (string) ($question['question'] ?? 'Вопрос'),
                    'selected' => $selected,
                    'selected_label' => self::resolveAnswerText($question, $selected, $custom),
                    'custom' => $custom,
                    'points' => $selectedPoints,
                    'max_points' => $maxForQuestion,
                    'penalty_points' => $questionPenalty,
                    'is_critical' => $isSleepingCritical,
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

    public static function resolveResultZone(int $penaltyPoints, bool $hasCriticalFailure): string
    {
        if ($penaltyPoints <= 0) {
            return 'green';
        }

        if ($hasCriticalFailure || $penaltyPoints >= 8) {
            return 'red';
        }

        return 'yellow';
    }

    protected static function resolveSelectedOption(array $options, string $selected): array
    {
        $maxForQuestion = 0;
        $selectedOption = null;
        $selectedPoints = 0;

        foreach ($options as $optionIndex => $option) {
            $points = (int) ($option['points'] ?? 0);

            if ($points > $maxForQuestion) {
                $maxForQuestion = $points;
            }

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
                $selectedOption = $option;
                $selectedPoints = $points;
            }
        }

        return [$maxForQuestion, $selectedOption, $selectedPoints];
    }

    protected static function isSleepingPlacesCriticalQuestion(string $roomTitle, int $questionIndex): bool
    {
        $normalizedTitle = Str::of($roomTitle)
            ->lower()
            ->replace(['ё'], ['е'])
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

            if ($selected !== '' && ($selected === $value || $selected === $optionLabel || $selected === 'option_' . $optIndex)) {
                $label = $optionLabel;
                break;
            }
        }

        if ($label !== '' && $custom !== '') {
            return $label . ' / ' . $custom;
        }

        if ($label !== '') {
            return $label;
        }

        if ($custom !== '') {
            return $custom;
        }

        return '—';
    }
}
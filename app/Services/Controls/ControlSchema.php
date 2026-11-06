<?php

namespace App\Services\Controls;

final class ControlSchema
{
    public function __construct(
        private readonly int $controlId,
        private readonly array $rooms,
    ) {}

    public function version(): string
    {
        return hash('sha256', self::encode([
            'format_version' => 1,
            'control_id' => $this->controlId,
            'rooms' => $this->orderedRooms(),
        ]));
    }

    /**
     * Stable question identifiers use the full question and room configuration.
     * Position only disambiguates identical duplicates, which are excluded from
     * cross-version automatic mapping.
     */
    public function questionIds(): array
    {
        return $this->identityMap()['ids'];
    }

    public function attachQuestionIds(array $responses): array
    {
        foreach ($this->questionIds() as $roomIndex => $questions) {
            foreach ($questions as $questionIndex => $questionId) {
                $responses[$roomIndex][$questionIndex] = is_array($responses[$roomIndex][$questionIndex] ?? null)
                    ? $responses[$roomIndex][$questionIndex]
                    : [];
                $responses[$roomIndex][$questionIndex]['question_id'] = $questionId;
            }
        }

        return $responses;
    }

    /**
     * Map only exact, unambiguous identities. Unmatched answers remain in the old draft.
     *
     * @return array{responses: array, mapped: int, unmapped: int}
     */
    public function mapAnswersFrom(self $source, array $sourceResponses): array
    {
        $sourceMap = $source->identityMap();
        $targetMap = $this->identityMap();
        $mappedResponses = [];
        $mapped = 0;
        $unmapped = 0;

        foreach ($sourceMap['ids'] as $oldRoomIndex => $questions) {
            foreach ($questions as $oldQuestionIndex => $questionId) {
                $answer = $sourceResponses[$oldRoomIndex][$oldQuestionIndex] ?? [];
                if (! self::hasAnswerData($answer)) {
                    continue;
                }

                if (
                    ($sourceMap['ambiguous'][$questionId] ?? true)
                    || ($targetMap['ambiguous'][$questionId] ?? true)
                    || ! isset($targetMap['positions'][$questionId])
                ) {
                    $unmapped++;

                    continue;
                }

                [$roomIndex, $questionIndex] = $targetMap['positions'][$questionId];
                $mappedResponses[$roomIndex][$questionIndex] = is_array($answer) ? $answer : [];
                $mappedResponses[$roomIndex][$questionIndex]['question_id'] = $questionId;
                $mapped++;
            }
        }

        return ['responses' => $mappedResponses, 'mapped' => $mapped, 'unmapped' => $unmapped];
    }

    /**
     * Requiredness matches the existing Focus rule: either a selected value or
     * custom text counts as filled. Scoring remains in ControlResponse.
     */
    public function validateAnswers(array $answers, bool $requireComplete = false): array
    {
        $errors = [];

        foreach ($this->orderedRooms() as $roomIndex => $room) {
            $items = is_array($room['items'] ?? null) ? array_values($room['items']) : [];
            $roomOptional = (bool) ($room['is_optional'] ?? false);

            foreach ($items as $questionIndex => $question) {
                if (! is_array($question)) {
                    $errors["answers.$roomIndex.$questionIndex"] = 'Некорректная схема вопроса.';

                    continue;
                }

                $answer = $answers[$roomIndex][$questionIndex] ?? [];
                $key = "answers.$roomIndex.$questionIndex";
                if (! is_array($answer)) {
                    $errors[$key] = 'Некорректный ответ.';

                    continue;
                }

                $selectedValue = $answer['selected'] ?? '';
                $customValue = $answer['custom'] ?? '';
                if (! is_string($selectedValue) || ! is_string($customValue)) {
                    $errors[$key] = 'Некорректный формат ответа.';

                    continue;
                }

                $selected = trim((string) $selectedValue);
                $custom = trim((string) $customValue);
                $answerType = (string) ($question['answer_type'] ?? 'options');

                if (array_key_exists('corrective', $answer) && ! is_array($answer['corrective'])) {
                    $errors[$key] = 'Некорректный формат пояснения.';

                    continue;
                }

                $corrective = is_array($answer['corrective'] ?? null) ? $answer['corrective'] : [];
                if (
                    (isset($corrective['repeats']) && ! is_bool($corrective['repeats']))
                    || (isset($corrective['recheck']) && ! is_bool($corrective['recheck']))
                    || (isset($corrective['action']) && ! is_string($corrective['action']))
                ) {
                    $errors[$key] = 'Некорректный формат пояснения.';

                    continue;
                }

                if (mb_strlen((string) ($corrective['action'] ?? '')) > 2000) {
                    $errors[$key] = 'Текст пояснения: максимум 2000 символов.';
                }

                if ($selected !== '' && ! in_array($selected, self::allowedValues($question), true)) {
                    $errors[$key] = 'Выбранный вариант отсутствует в текущей схеме вопроса.';
                }

                if (mb_strlen($custom) > 2000) {
                    $errors[$key] = 'Текстовый ответ: максимум 2000 символов.';
                }

                if ($requireComplete && ! $roomOptional && ! (bool) ($question['is_optional'] ?? false)
                    && $selected === '' && $custom === '') {
                    $errors[$key] = 'Ответьте на обязательный вопрос.';
                }

                if ($answerType === 'text' && $selected !== '') {
                    $errors[$key] = 'Для текстового вопроса укажите текстовый ответ.';
                }
            }
        }

        return $errors;
    }

    private function identityMap(): array
    {
        $rooms = $this->orderedRooms();
        $roomFingerprints = [];
        $roomFingerprintCounts = [];
        $questionFingerprintCounts = [];

        foreach ($rooms as $roomIndex => $room) {
            $metadata = $room;
            unset($metadata['items']);
            $roomFingerprint = hash('sha256', self::encode($metadata));
            $roomFingerprints[$roomIndex] = $roomFingerprint;
            $roomFingerprintCounts[$roomFingerprint] = ($roomFingerprintCounts[$roomFingerprint] ?? 0) + 1;

            foreach ((array) ($room['items'] ?? []) as $question) {
                $fingerprint = hash('sha256', self::encode(is_array($question) ? $question : []));
                $questionFingerprintCounts[$roomFingerprint][$fingerprint]
                    = ($questionFingerprintCounts[$roomFingerprint][$fingerprint] ?? 0) + 1;
            }
        }

        $roomOccurrences = [];
        $questionOccurrences = [];
        $ids = [];
        $positions = [];
        $ambiguous = [];

        foreach ($rooms as $roomIndex => $room) {
            $roomFingerprint = $roomFingerprints[$roomIndex];
            $roomOccurrence = $roomOccurrences[$roomFingerprint] ?? 0;
            $roomOccurrences[$roomFingerprint] = $roomOccurrence + 1;
            $questionOccurrences[$roomIndex] = [];

            foreach (array_values((array) ($room['items'] ?? [])) as $questionIndex => $question) {
                $question = is_array($question) ? $question : [];
                $questionFingerprint = hash('sha256', self::encode($question));
                $questionOccurrence = $questionOccurrences[$roomIndex][$questionFingerprint] ?? 0;
                $questionOccurrences[$roomIndex][$questionFingerprint] = $questionOccurrence + 1;

                $questionId = 'q_'.hash('sha256', self::encode([
                    'identity_version' => 1,
                    'control_id' => $this->controlId,
                    'room' => $roomFingerprint,
                    'room_occurrence' => $roomOccurrence,
                    'question' => $questionFingerprint,
                    'question_occurrence' => $questionOccurrence,
                ]));

                $ids[$roomIndex][$questionIndex] = $questionId;
                $positions[$questionId] = [$roomIndex, $questionIndex];
                $ambiguous[$questionId] = ($roomFingerprintCounts[$roomFingerprint] ?? 0) > 1
                    || ($questionFingerprintCounts[$roomFingerprint][$questionFingerprint] ?? 0) > 1;
            }
        }

        return compact('ids', 'positions', 'ambiguous');
    }

    private function orderedRooms(): array
    {
        return array_map(function (mixed $room): array {
            $room = is_array($room) ? $room : [];
            if (is_array($room['items'] ?? null)) {
                $room['items'] = array_values($room['items']);
            }

            return $room;
        }, array_values($this->rooms));
    }

    private static function allowedValues(array $question): array
    {
        $values = [];
        foreach ((array) ($question['answer_options_scored'] ?? []) as $index => $option) {
            if (! is_array($option)) {
                continue;
            }

            $values[] = (string) ($option['value'] ?? $option['label'] ?? 'option_'.$index);
            if (isset($option['label'])) {
                $values[] = (string) $option['label'];
            }
            $values[] = 'option_'.$index;
        }

        return array_values(array_unique($values));
    }

    private static function hasAnswerData(mixed $answer): bool
    {
        if (! is_array($answer)) {
            return false;
        }

        $corrective = is_array($answer['corrective'] ?? null) ? $answer['corrective'] : [];

        return trim((string) ($answer['selected'] ?? '')) !== ''
            || trim((string) ($answer['custom'] ?? '')) !== ''
            || ! empty($answer['media'])
            || ! empty($corrective['action'])
            || is_bool($corrective['repeats'] ?? null)
            || is_bool($corrective['recheck'] ?? null);
    }

    private static function encode(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        $keys = array_keys($value);
        usort($keys, static fn ($left, $right): int => strcmp((string) $left, (string) $right));
        $canonical = [];

        foreach ($keys as $key) {
            $canonical[$key] = self::canonicalize($value[$key]);
        }

        return $canonical;
    }
}
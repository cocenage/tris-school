<?php

namespace App\Services\Telegram;

class ScheduledControlResponseClassifier
{
    public function classify(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace('ё', 'е', $text);
        $text = trim((string) preg_replace('/[^\p{L}\p{N}\s?]+/u', ' ', $text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        foreach ([
            'problem' => '/(?:^нет\b|\bне все\b|\bне (?:начал\p{L}*|заверш\p{L}*|приехал\p{L}*|проверен\p{L}*|готов\p{L}*|работает|получил\p{L}*)\b|\bесть проблем\p{L}*\b|\bне хватает\b)/u',
            'partial' => '/(?:\b\d+ из \d+\b|\bпочти все\b|\bодна осталась\b|\bвсе(?:\s+\p{L}+){0,3}\s+кроме\b)/u',
            'unclear' => '/(?:\bсейчас уточню\b|\bне знаю\b|\bпроверяем\b|\?)/u',
            'confirmed' => '/^(?:да(?: все (?:хорошо|ок|начались|начали|завершено|завершили))?|все (?:хорошо|ок|начались|начали|завершено|завершили)|готово|проверено|сделано|завершено)$/u',
        ] as $classification => $pattern) {
            if (preg_match($pattern, $text)) {
                return ['classification' => $classification, 'reason' => 'explicit_'.$classification];
            }
        }

        return ['classification' => 'unclear', 'reason' => 'no_bounded_pattern'];
    }
}

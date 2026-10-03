<?php

namespace App\Services\Telegram;

class ScheduledControlResponseInterpreter
{
    public function __construct(private readonly ScheduledControlResponseClassifier $classifier) {}

    public function interpret(string $text): array
    {
        $classification = $this->classifier->classify($text)['classification'];
        $status = match ($classification) {
            'confirmed' => 'ok',
            'partial' => 'partial',
            'problem' => 'problem',
            default => 'unknown',
        };
        $district = null;
        if (preg_match('/\bвсе\b.{0,35}\bкроме\s+([\p{L}-]+)/iu', $text, $match) === 1) {
            $district = $this->districtLabel($match[1]);
            $status = 'partial';
        }

        $delayMinutes = preg_match('/(?:опозда\p{L}*|задерж\p{L}*|позже)\b.{0,30}?(?:(?:(?:примерно|около)\s+)?(?:на\s+)?(\d{1,3})\s*(?:минут\p{L}*|мин\b)|минут\p{L}*\s+на\s+(\d{1,3})\b)/iu', $text, $match) === 1
            ? (int) ($match[1] ?: $match[2])
            : null;
        if ($delayMinutes !== null || preg_match('/\b(?:нет|не\s+хват\p{L}*)\s+ключ/iu', $text) === 1) {
            $status = 'problem';
        }
        $reason = match (true) {
            preg_match('/ключ\p{L}*/iu', $text) === 1 => 'нет ключей',
            preg_match('/курьер\p{L}*.{0,30}не\s+приехал|курьер\p{L}*.{0,30}не\s+забрал/iu', $text) === 1 => 'курьер не выполнил доставку',
            preg_match('/опозда\p{L}*|задерж\p{L}*|позже/iu', $text) === 1 => 'задержка',
            default => null,
        };

        return [
            'status' => $status,
            'district' => $district,
            'delay_minutes' => $delayMinutes,
            'reason' => $reason,
            'issue' => $status === 'problem' || $status === 'partial' ? trim($text) : null,
        ];
    }

    private function districtLabel(string $value): ?string
    {
        $normalized = mb_strtolower(str_replace('ё', 'е', $value));
        $known = ['como' => 'Como', 'комо' => 'Como'];
        foreach ($known as $alias => $label) {
            if ($normalized === $alias) {
                return $label;
            }
        }

        foreach (config('services.telegram.digest_districts', []) as $district) {
            $label = (string) ($district['label'] ?? '');
            if ($label !== '' && $normalized === mb_strtolower($label)) {
                return $label;
            }
        }

        return null;
    }
}

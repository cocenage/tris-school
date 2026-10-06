<?php

namespace App\Services\Telegram;

class ScheduledControlResponseClassifier
{
    public function classify(string $text): array
    {
        $districtLines = $this->districtLines($text);

        if ($districtLines !== []) {
            $states = array_map(fn (array $line): string => $this->districtClassification($line), $districtLines);

            if (in_array('problem', $states, true)) {
                return ['classification' => 'problem', 'reason' => 'district_problem'];
            }

            if (in_array('confirmed', $states, true)) {
                $confirmedDistricts = collect($districtLines)
                    ->filter(fn (array $line, int $index): bool => $states[$index] === 'confirmed')
                    ->pluck('district')->unique()->count();
                $configuredDistricts = count($this->districtLabels());
                $classification = $configuredDistricts > 0 && $confirmedDistricts < $configuredDistricts
                    ? 'partial'
                    : 'confirmed';

                return ['classification' => $classification, 'reason' => 'district_'.$classification];
            }
        }

        $text = mb_strtolower(trim($text));
        $text = str_replace('ё', 'е', $text);
        $text = trim((string) preg_replace('/[^\p{L}\p{N}\s?]+/u', ' ', $text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        foreach ([
            'problem' => '/(?:^нет\b|\bне все\b|\bне (?:начал\p{L}*|заверш\p{L}*|приехал\p{L}*|проверен\p{L}*|готов\p{L}*|работает|получил\p{L}*)\b|\bесть проблем\p{L}*\b|\bне хватает\b|\b(?:опозда\p{L}*|задерж\p{L}*|позже)\b.{0,30}\b(?:(?:(?:примерно|около)\s+)?на\s+\d{1,3}\s+минут\p{L}*|минут\p{L}*\s+на\s+\d{1,3})\b)/u',
            'partial' => '/(?:\b\d+ из \d+\b|\bпочти все\b|\bодна осталась\b|\bвсе(?:\s+\p{L}+){0,3}\s+кроме\b)/u',
            'unclear' => '/(?:\bсейчас уточню\b|\bне знаю\b|\bпроверяем\b|\?)/u',
            'confirmed' => '/^(?:да(?: все (?:хорошо|ок|начались|начали|проверен\p{L}*|завершено|завершили))?|все (?:хорошо|ок|начались|начали|проверен\p{L}*|завершено|завершили)|готово|проверен\p{L}*|сделано|завершено)$/u',
        ] as $classification => $pattern) {
            if (preg_match($pattern, $text)) {
                return ['classification' => $classification, 'reason' => 'explicit_'.$classification];
            }
        }

        return ['classification' => 'unclear', 'reason' => 'no_bounded_pattern'];
    }

    /** @return array<int, array{district: string, marker: ?string, text: string, line_index: int}> */
    public function districtLines(string $text): array
    {
        $labels = $this->districtLabels();
        if ($labels === []) {
            return [];
        }

        $aliases = ['Комо', ...$labels];
        $pattern = '/^\s*(?:(🟢|🔴|✅|❌|⚪)\s*)?('.implode('|', array_map(
            fn (string $label): string => preg_quote($label, '/'),
            array_values(array_unique($aliases)),
        )).')(?=$|[\s,;:—–-])\s*(.*)$/iu';
        $matches = [];

        foreach (preg_split('/\R/u', str_replace("\0", '', $text)) ?: [] as $lineIndex => $line) {
            if (preg_match($pattern, trim($line), $match) === 1) {
                $matches[] = [
                    'district' => $this->districtLabel((string) $match[2]),
                    'marker' => ($match[1] ?? '') !== '' ? $match[1] : null,
                    'text' => trim((string) ($match[3] ?? '')),
                    'line_index' => $lineIndex,
                ];
            }
        }

        return $matches;
    }

    /** @return list<string> */
    public function districtLabels(): array
    {
        return collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $district): string => trim((string) (is_array($district) ? ($district['label'] ?? '') : '')))
            ->filter()
            ->unique(fn (string $label): string => mb_strtolower($label))
            ->sortByDesc(fn (string $label): int => mb_strlen($label))
            ->values()
            ->all();
    }

    private function districtClassification(array $line): string
    {
        $text = mb_strtolower(str_replace('ё', 'е', (string) $line['text']));
        if (preg_match('/\b(?:нет|не\s+(?:начал\p{L}*|заверш\p{L}*|закончил\p{L}*|приехал\p{L}*|забрал\p{L}*|выплатил\p{L}*|проверил\p{L}*)|опозда\p{L}*|задерж\p{L}*|проблем\p{L}*)\b/u', $text) === 1) {
            return 'problem';
        }
        if (in_array($line['marker'], ['🔴', '❌'], true)) {
            return 'problem';
        }
        if (preg_match('/\b(?:да|не\s+было|начал\p{L}*|заверш\p{L}*|закончил\p{L}*|заканч\p{L}*|готов\p{L}*|проверен\p{L}*|приехал\p{L}*|получил\p{L}*|выплатил\p{L}*)\b/u', $text) === 1) {
            return 'confirmed';
        }
        if (in_array($line['marker'], ['🟢', '✅'], true)) {
            return 'confirmed';
        }

        return 'unclear';
    }

    private function districtLabel(string $value): string
    {
        if (mb_strtolower(str_replace('ё', 'е', $value)) === 'комо') {
            return collect($this->districtLabels())->first(fn (string $label): bool => mb_strtolower($label) === 'como') ?? $value;
        }

        foreach ($this->districtLabels() as $label) {
            if (mb_strtolower($label) === mb_strtolower($value)) {
                return $label;
            }
        }

        return $value;
    }
}

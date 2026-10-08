<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

class TelegramTomorrowSummaryFormatter
{
    public function format(array $summary): string
    {
        $date = CarbonImmutable::parse($summary['date'], $summary['timezone'])->format('d.m');
        $sections = ["🌅 Завтра · {$date}"];

        if ($summary['absences'] !== []) {
            $lines = ['🏖 Не работают'];
            foreach ($summary['absences'] as $absence) {
                $lines[] = '• '.$absence['name'].' — '.$absence['reason'];
            }
            $sections[] = implode("\n", $lines);
        }

        if ($summary['weather'] !== null) {
            $weather = $summary['weather'];
            $lines = ['🌤 Погода', $weather['emoji'].' '.$weather['summary']];
            if (filled($weather['advice'] ?? null)) {
                $lines[] = $weather['advice'];
            }
            $sections[] = implode("\n", $lines);
        }

        if ($summary['absences'] === [] && $summary['weather'] === null) {
            $sections[] = '✅ На завтра подтверждённых особенностей нет.';
        } else {
            $sections[] = '✅ Остальное без особенностей';
        }

        return implode("\n\n", $sections);
    }
}

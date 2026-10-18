<?php

namespace App\Services\Telegram;

use Carbon\Carbon;

class TelegramLinenDefectReportFormatter
{
    public function format(array $report, ?string $districtKey = null): string
    {
        $districts = collect($report['districts']);
        if ($districtKey !== null) {
            $districts = $districts->only([$districtKey]);
        }
        $cases = collect($report['cases'])->filter(
            fn (array $case): bool => $districtKey === null || $case['district'] === $districtKey,
        );
        $known = $districts->sum('known_units');
        $unknown = $cases->sum(fn (array $case): int => count(array_filter(
            $case['details'], fn (array $detail): bool => $detail['quantity'] === null,
        )));
        $incomplete = ($districtKey === null && ($report['incomplete'] ?? false))
            || $districts->isEmpty() || $districts->contains(
                fn (array $district): bool => $district['source_status'] !== 'available',
            );
        $date = Carbon::parse($report['date'], $report['timezone'])->format('d.m.Y');
        $lines = ['🧺 TRIS — Брак белья · '.$date, ''];

        if ($known > 0) {
            $lines[] = '<b>Всего:</b> '.$known.' шт. с указанным количеством';
        } elseif (! $incomplete && $cases->isEmpty()) {
            $lines[] = '✅ Подтверждённых сообщений о браке белья не найдено.';
        } elseif ($cases->where('quantity_confidence', 'uncertain_duplicate')->isNotEmpty()) {
            $lines[] = 'Количество не подтверждено: возможный повтор сообщений.';
        } elseif ($cases->isNotEmpty()) {
            $lines[] = 'Количество бракованных единиц не указано.';
        }

        if ($unknown > 0) {
            $lines[] = 'Случаи без указанного количества: '.$unknown.' (не входят в итог).';
        }

        if ($known > 0) {
            $lines[] = '';
            $lines[] = '<b>По районам:</b>';
            foreach ($districts as $district) {
                if ($district['known_units'] > 0) {
                    $lines[] = '• '.htmlspecialchars($district['label'], ENT_QUOTES, 'UTF-8').' — '.$district['known_units'].' шт.';
                }
            }
        }

        if ($cases->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Детализация:</b>';
            foreach ($cases as $case) {
                $location = $case['location'] ?: $case['district_label'];
                $details = collect($case['details'])->map(fn (array $detail): string => $detail['quantity'] === null
                    ? $detail['item'].' — количество не указано'
                    : $detail['quantity'].' × '.$detail['item'])->implode(', ');
                $lines[] = '📍 '.htmlspecialchars($location, ENT_QUOTES, 'UTF-8').' — '
                    .htmlspecialchars($details.', '.$case['defect'], ENT_QUOTES, 'UTF-8')
                    .(($case['quantity_confidence'] ?? null) === 'uncertain_duplicate'
                        ? ' — возможный повтор, количество не входит в итог' : '');
                if ($case['source_url'] !== null) {
                    $lines[] = '🔗 '.$case['source_url'];
                }
            }
        }

        if ($incomplete) {
            $lines[] = '';
            $lines[] = '⚠️ Данные неполные: нет входящих сообщений или OI обработал их не полностью.';
        }

        return implode("\n", $lines);
    }
}

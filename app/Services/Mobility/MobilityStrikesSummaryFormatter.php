<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;

class MobilityStrikesSummaryFormatter
{
    public function formatMorningSection(array $summary): string
    {
        $start = CarbonImmutable::parse($summary['date'], 'Europe/Rome');
        $strikes = collect($summary['strikes'] ?? [])
            ->reject(fn (array $strike): bool => (bool) ($strike['cancelled'] ?? false))
            ->take(6)
            ->values();

        if ($strikes->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($strikes as $strike) {
            $line = '🚇 Забастовка '.$this->morningTransport($strike);
            $date = $this->morningDate($strike, $start);
            $time = $this->confirmedTime($strike['duration'] ?? null);

            if ($date !== null) {
                $line .= ' · '.$date;
            }
            if ($time !== null) {
                $line .= ' · '.$time;
            }

            $lines[] = $line;
            $lines[] = $this->morningImpact($strike);
        }

        return implode("\n", $lines);
    }

    public function format(array $summary): string
    {
        $date = CarbonImmutable::parse($summary['date'], 'Europe/Rome')->format('d.m.Y');
        $lines = ['✊ TRIS — Забастовки · '.$date];
        $strikes = $summary['strikes'];

        if ($strikes === []) {
            $lines[] = '';
            $status = $summary['source_status'] ?? [];
            $lines[] = ($status['state'] ?? 'unknown') === 'complete'
                ? '✅ На сегодня подтверждённых забастовок, влияющих на работу TRIS, не найдено.'
                : $this->incompleteMorningStatus($status)
                    ?? '⚠️ Свежий статус источников неизвестен; отсутствие забастовок не подтверждено.';

            return implode("\n", $lines);
        }

        foreach (array_slice($strikes, 0, 6) as $strike) {
            $lines[] = '';
            $lines[] = ($strike['cancelled'] ? '✅ ' : '⚠️ ').$this->clean($strike['operator'])
                .($strike['cancelled'] ? ' — забастовка отменена.' : ($strike['updated'] ? ' — данные обновлены.' : ''));

            if (! $strike['cancelled']) {
                if ($strike['duration'] !== null) {
                    $lines[] = 'Время: '.$this->clean($strike['duration']);
                }
                if ($strike['guarantee'] !== null) {
                    $lines[] = $this->clean($strike['guarantee']);
                }
                if ($strike['effect'] !== null) {
                    $lines[] = $strike['effect'];
                }
                if ($strike['detail'] !== null && $strike['duration'] === null) {
                    $lines[] = $this->clean($strike['detail']);
                }
            }

            $lines[] = 'Источник: '.implode(', ', array_unique($strike['sources']));
        }

        if (count($strikes) > 6) {
            $lines[] = '';
            $lines[] = 'Ещё '.(count($strikes) - 6).' подтверждённых сообщений в источниках.';
        }

        if (collect($strikes)->every(fn (array $strike): bool => $strike['cancelled'])) {
            $lines[] = '';
            $lines[] = '✅ Действующих забастовок не найдено.';
        }

        $warning = $this->incompleteMorningStatus($summary['source_status'] ?? []);
        if ($warning !== null) {
            $lines[] = '';
            $lines[] = $warning;
        }

        return implode("\n", $lines);
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strimwidth($value, 0, 180, '…');
    }

    private function morningTransport(array $strike): string
    {
        $sector = mb_strtolower((string) ($strike['sector'] ?? ''));
        $source = mb_strtolower((string) ($strike['source'] ?? ''));

        return str_contains($sector, 'ferroviar') || $source === 'trenord'
            ? 'железнодорожного транспорта'
            : (str_contains($sector, 'trasporto pubblico') || $source === 'atm'
                ? 'городского транспорта'
                : 'транспорта');
    }

    private function morningImpact(array $strike): string
    {
        $sector = mb_strtolower((string) ($strike['sector'] ?? ''));
        $source = mb_strtolower((string) ($strike['source'] ?? ''));

        return str_contains($sector, 'ferroviar') || $source === 'trenord'
            ? 'Возможны перебои в движении поездов.'
            : (str_contains($sector, 'trasporto pubblico') || $source === 'atm'
                ? 'Возможны перебои в работе городского транспорта.'
                : 'Возможны перебои в работе транспорта.');
    }

    private function morningDate(array $strike, CarbonImmutable $reportDate): ?string
    {
        $start = CarbonImmutable::parse($strike['starts_at'] ?? $reportDate->toDateString(), 'Europe/Rome');
        $end = CarbonImmutable::parse($strike['ends_at'] ?? $start->toDateString(), 'Europe/Rome');

        if ($start->toDateString() === $reportDate->toDateString()
            && $end->toDateString() === $reportDate->toDateString()) {
            return null;
        }

        $label = $start->format('d.m.Y');

        return $end->toDateString() !== $start->toDateString()
            ? $label.'–'.$end->format('d.m.Y')
            : $label;
    }

    private function confirmedTime(mixed $duration): ?string
    {
        $duration = trim((string) $duration);
        $range = '(?:[01]?\d|2[0-3]):[0-5]\d\s*[-–—]\s*(?:[01]?\d|2[0-3]):[0-5]\d';

        if ($duration === '' || preg_match('/^'.$range.'(?:\s*(?:e|,|;)\s*'.$range.')*$/iu', $duration) !== 1) {
            return null;
        }

        $duration = preg_replace('/\s*[-–—]\s*/u', '–', $duration);

        return trim((string) preg_replace('/\s*(?:e|;)\s*/iu', ', ', $duration));
    }

    private function strikeDate(array $strike): string
    {
        $start = CarbonImmutable::parse($strike['starts_at'] ?? 'now', 'Europe/Rome')->format('d.m.Y');
        $end = filled($strike['ends_at'] ?? null)
            ? CarbonImmutable::parse($strike['ends_at'], 'Europe/Rome')->format('d.m.Y')
            : $start;

        return $start === $end ? $start : $start.'–'.$end;
    }

    private function sector(string $sector): string
    {
        $normalized = mb_strtolower($sector);

        return match (true) {
            str_contains($normalized, 'ferroviar') => 'железнодорожный транспорт',
            str_contains($normalized, 'trasporto pubblico locale') => 'общественный транспорт',
            default => $this->clean($sector),
        };
    }

    private function emptyMorningStatus(array $status): string
    {
        if (($status['state'] ?? 'unknown') === 'complete') {
            return '✅ На этот период подтверждённых забастовок не найдено.';
        }

        return $this->incompleteMorningStatus($status)
            ?? '⚠️ Свежий статус источников неизвестен; отсутствие забастовок не подтверждено.';
    }

    private function incompleteMorningStatus(array $status): ?string
    {
        return match ($status['state'] ?? 'unknown') {
            'complete' => null,
            'partial' => '⚠️ Источники забастовок обработаны частично. Нет пригодных данных от: '
                .implode(', ', $status['unavailable'] ?? []).'.',
            'stale' => '⚠️ Статус источников устарел; наличие или отсутствие забастовок не подтверждено.',
            default => '⚠️ Нет свежего статуса источников ('
                .implode(', ', $status['unavailable'] ?? []).'); отсутствие забастовок не подтверждено.',
        };
    }
}

<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;

class MobilityStrikesSummaryFormatter
{
    public function formatMorningSection(array $summary): string
    {
        $start = CarbonImmutable::parse($summary['date'], 'Europe/Rome');
        $end = CarbonImmutable::parse($summary['window_end'] ?? $summary['date'], 'Europe/Rome');
        $range = $start->format('d.m.Y');
        if ($end->toDateString() !== $start->toDateString()) {
            $range .= '–'.$end->format('d.m.Y');
        }

        $lines = ['🚇 <b>Забастовки · '.$range.'</b>'];
        $strikes = $summary['strikes'] ?? [];

        if ($strikes === []) {
            $lines[] = $this->emptyMorningStatus($summary['source_status'] ?? []);

            return implode("\n", $lines);
        }

        foreach (array_slice($strikes, 0, 6) as $strike) {
            $lines[] = '';
            $date = $this->strikeDate($strike);
            $tomorrow = ! $strike['cancelled']
                && ($strike['starts_at'] ?? null) === $start->addDay()->toDateString();
            $status = $strike['cancelled'] ? '✅ ' : ($tomorrow ? '🚨 Завтра: ' : '⚠️ ');
            $suffix = $strike['cancelled'] ? ' — забастовка отменена.' : ($strike['updated'] ? ' — данные обновлены.' : '');
            $lines[] = $status.$this->clean($strike['operator']).' · '.$date.$suffix;

            if ($strike['sector'] !== null) {
                $lines[] = 'Сфера: '.$this->sector($strike['sector']);
            }
            if ($strike['geography'] !== null) {
                $lines[] = 'География: '.$this->clean($strike['geography']);
            }
            if (! $strike['cancelled']) {
                if ($strike['duration'] !== null) {
                    $lines[] = 'Время: '.$this->clean($strike['duration']);
                }
                if ($strike['guarantee'] !== null) {
                    $lines[] = $this->clean($strike['guarantee']);
                }
                if ($strike['effect'] !== null) {
                    $lines[] = 'Влияние: '.$strike['effect'];
                }
                if ($strike['detail'] !== null && $strike['duration'] === null) {
                    $lines[] = $this->clean($strike['detail']);
                }
            }

            $source = $this->clean(implode(', ', array_unique($strike['sources'] ?? [$strike['source_label'] ?? 'Источник'])));
            $url = trim((string) ($strike['url'] ?? ''));
            $lines[] = $url !== ''
                ? 'Источник: <a href="'.e($url).'">'.$source.'</a>'
                : 'Источник: '.$source;
        }

        if (count($strikes) > 6) {
            $lines[] = '';
            $lines[] = 'Ещё '.(count($strikes) - 6).' подтверждённых сообщений в источниках.';
        }

        $warning = $this->incompleteMorningStatus($summary['source_status'] ?? []);
        if ($warning !== null) {
            $lines[] = '';
            $lines[] = $warning;
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

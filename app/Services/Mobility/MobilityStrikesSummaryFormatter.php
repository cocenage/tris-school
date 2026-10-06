<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;

class MobilityStrikesSummaryFormatter
{
    public function format(array $summary): string
    {
        $date = CarbonImmutable::parse($summary['date'], 'Europe/Rome')->format('d.m.Y');
        $lines = ['✊ TRIS — Забастовки · '.$date];
        $strikes = $summary['strikes'];

        if ($strikes === []) {
            $lines[] = '';
            $lines[] = '✅ На сегодня подтверждённых забастовок, влияющих на работу TRIS, не найдено.';

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

        return implode("\n", $lines);
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strimwidth($value, 0, 180, '…');
    }
}

<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MobilityStrikesSummaryFormatter
{
    public function formatTelegramAlert(MobilityAlert $alert, array $districts): string
    {
        $item = $alert->strike_metadata ?? [];
        $kind = $item['notification_kind'] ?? 'new';
        $coverage = app(MobilityStrikeCoverage::class);
        $source = (string) $alert->source;
        $mode = $coverage->transportMode($source, $item);
        $operator = $coverage->operator($source, $item);
        $impact = $mode === 'rail' ? 'Возможны перебои с поездами' : 'Возможны перебои с городским транспортом';

        $lines = [match ($kind) {
            'cancelled' => '✅ <b>Забастовка отменена</b>',
            'postponed' => '🔄 <b>Забастовка перенесена</b>',
            'updated' => '🔄 <b>Обновление забастовки</b>',
            default => '🚨 <b>Забастовка транспорта</b>',
        }];

        $labels = array_map(fn (string $key): string => e(config('services.telegram.digest_districts.'.$key.'.label', ucfirst($key))), $districts);
        $lines[] = '📍 <b>Зоны TRIS:</b> '.implode(' · ', $labels);
        $inactive = in_array($kind, ['cancelled', 'postponed'], true);
        $lines[] = '📅 <b>'.$this->telegramPeriod($alert, $inactive ? null : ($item['duration'] ?? null)).'</b>';

        if ($kind === 'postponed') {
            $lines[] = 'Новая дата не подтверждена.';
        } elseif ($kind === 'updated' && filled($item['previous']['starts_at'] ?? null)
            && $item['previous']['starts_at'] !== $alert->starts_at?->toDateString()) {
            $lines[] = 'Прежняя дата: '.$this->telegramDate(CarbonImmutable::parse($item['previous']['starts_at'], 'Europe/Rome'));
        }

        $network = $mode === 'rail' ? 'Железнодорожный транспорт' : 'Городской транспорт';
        $transport = $inactive ? ($operator ?? $network) : ($operator === null ? $impact : $operator.' · '.mb_strtolower($impact));
        $lines[] = ($mode === 'rail' ? '🚆 ' : '🚇 ').$transport;

        if (filled($alert->url)) {
            $lines[] = '';
            $lines[] = '<a href="'.e($alert->url).'">Официальное объявление</a>';
        }

        return implode("\n", $lines);
    }

    private function telegramPeriod(MobilityAlert $alert, ?string $duration): string
    {
        if ($alert->starts_at === null) {
            return 'Дата проведения не подтверждена';
        }

        $date = $this->telegramDate($alert->starts_at);
        $end = $alert->ends_at;
        $time = $this->confirmedTime($duration);
        if ($end !== null && ! $alert->starts_at->isSameDay($end)) {
            $endDate = $this->telegramDate($end);
            $parts = $time === null ? [] : explode('–', $time);
            if (count($parts) === 2) {
                return $date.', '.$parts[0].' — '.$endDate.', '.$parts[1];
            }

            $date .= ' — '.$endDate;
        }

        if ($time === null && preg_match('/^(\d+)\s+ore?$/iu', trim((string) $duration), $match) === 1) {
            $time = $match[1].' ч';
        }

        return $time === null ? $date : $date.' · '.$time;
    }

    private function telegramDate(CarbonInterface $date): string
    {
        $format = $date->year === CarbonImmutable::now('Europe/Rome')->year ? 'D MMMM' : 'D MMMM YYYY';

        return $date->locale('ru')->isoFormat($format);
    }

    public function formatMorningSection(array $summary): string
    {
        $start = CarbonImmutable::parse($summary['date'], 'Europe/Rome');
        $strikes = collect($summary['strikes'] ?? [])
            ->filter(fn (array $strike): bool => in_array($strike['status'] ?? null, ['scheduled', 'updated'], true)
                && ! (bool) ($strike['cancelled'] ?? false))
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
            $lines = array_merge($lines, $this->sourceDiagnostics($status));

            return implode("\n", $lines);
        }

        foreach (array_slice($strikes, 0, 6) as $strike) {
            $lines[] = '';
            $lines[] = match ($strike['status'] ?? null) {
                'cancelled' => '✅ '.$this->clean($strike['operator']).' — забастовка отменена.',
                'postponed' => '📅 '.$this->clean($strike['operator']).' — забастовка перенесена; новая дата не подтверждена.',
                default => '⚠️ '.$this->clean($strike['operator']).(($strike['updated'] ?? false) ? ' — данные обновлены.' : ''),
            };

            if (($strike['status'] ?? null) === 'postponed') {
                if ($strike['starts_at'] !== null) {
                    $lines[] = 'Первоначальная дата: '.$this->strikeDate($strike);
                }
            } elseif (! $strike['cancelled']) {
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
        $lines = array_merge($lines, $this->sourceDiagnostics($summary['source_status'] ?? []));

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

    private function sourceDiagnostics(array $status): array
    {
        $lines = [];
        if (filled($status['checked_at'] ?? null)) {
            try {
                $checkedAt = CarbonImmutable::parse($status['checked_at'])->setTimezone('Europe/Rome');
                $lines[] = '';
                $lines[] = 'Источники проверены: '.$checkedAt->format('d.m.Y H:i').' Europe/Rome.';
            } catch (\Throwable) {
                // Invalid cached timestamps are represented as unknown above.
            }
        }

        $lastSuccess = collect($status['sources'] ?? [])
            ->filter(fn (array $source): bool => filled($source['last_successful_at'] ?? null))
            ->map(function (array $source): ?string {
                try {
                    return ($source['label'] ?? 'Источник').': '.CarbonImmutable::parse($source['last_successful_at'])
                        ->setTimezone('Europe/Rome')->format('d.m.Y H:i');
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();
        if ($lastSuccess !== []) {
            $lines[] = 'Последняя успешная проверка Europe/Rome: '.implode('; ', $lastSuccess).'.';
        }

        $problems = collect($status['sources'] ?? [])
            ->filter(fn (array $source): bool => in_array($source['status'] ?? null, ['unavailable', 'unrecognized', 'stale', 'unknown'], true))
            ->map(fn (array $source): string => ($source['label'] ?? 'Источник').' — '.match ($source['status'] ?? 'unknown') {
                'unavailable' => 'недоступен',
                'unrecognized' => 'формат не распознан',
                'stale' => 'данные устарели',
                default => 'нет данных',
            })
            ->values()
            ->all();

        if ($problems !== []) {
            $lines[] = 'Проблемы источников: '.implode('; ', $problems).'.';
        }

        return $lines;
    }
}

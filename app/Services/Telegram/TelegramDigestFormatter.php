<?php

namespace App\Services\Telegram;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Formats deterministic operational contracts without inventing facts.
 */
class TelegramDigestFormatter
{
    public function morning(array $context): string
    {
        $lines = [
            '🌅 Утренняя сводка',
            $this->dateLine($context),
            '',
            'Сегодня',
            sprintf(
                '- Работающих сотрудников: %d%s',
                count($context['staff']['working'] ?? []),
                is_numeric($context['staff']['shift']['total'] ?? null)
                    ? ' из ' . (int) $context['staff']['shift']['total'] . ' назначенных'
                    : '',
            ),
        ];

        if (array_key_exists('total', $context['tasks'] ?? [])) {
            $lines[] = sprintf(
                '- Задач: %d, открытых: %d',
                (int) ($context['tasks']['total'] ?? 0),
                (int) ($context['tasks']['open'] ?? 0),
            );
        }

        $absences = collect($context['staff']['not_working'] ?? [])
            ->map(fn (array $user): string => $this->personLine($user))
            ->filter()
            ->take(10)
            ->values();

        if ($absences->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Отсутствия';
            foreach ($absences as $absence) {
                $lines[] = '- ' . $absence;
            }
        }

        $events = collect($context['calendar']['events'] ?? []);
        $peakEvents = $events->filter(fn (array $event): bool => ($event['type'] ?? null) === 'peak');
        $otherEvents = $events->reject(fn (array $event): bool => ($event['type'] ?? null) === 'peak')->take(7);

        if ($peakEvents->isNotEmpty() || $otherEvents->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Календарь';
            foreach ($peakEvents->take(7) as $event) {
                $lines[] = '- ' . $this->value($event['title'] ?? null) . ' (пиковая дата)';
            }
            foreach ($otherEvents as $event) {
                $lines[] = '- ' . $this->value($event['title'] ?? null);
            }
        }

        $pending = collect($context['requests']['items'] ?? [])
            ->filter(fn (array $item): bool => ! in_array(
                $item['status'] ?? $item['request_status'] ?? null,
                ['approved', 'rejected', 'cancelled'],
                true,
            ))
            ->take(7);

        $mobilityItems = $this->meaningfulMobilityItems($context);
        $mobilityCriticalCount = $mobilityItems->count();

        $risks = collect($context['risks'] ?? [])
            ->reject(fn (array $risk): bool => ($risk['source'] ?? null) === 'mobility' || ($risk['code'] ?? null) === 'mobility_alert')
            ->sortBy(fn (array $risk): int => match ($risk['level'] ?? 'info') {
                'critical' => 1,
                'high' => 2,
                'warning' => 3,
                default => 4,
            })
            ->values();

        if ($mobilityCriticalCount > 0) {
            $risks->prepend([
                'level' => 'high',
                'code' => 'mobility_summary',
                'message' => '🚇 ' . $mobilityCriticalCount . ' существенных транспортных ограничений',
            ]);
        }

        if ($pending->isNotEmpty() || $risks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Требует внимания';
            foreach ($risks->take(7) as $risk) {
                $lines[] = sprintf(
                    '- %s',
                    $this->value($risk['message'] ?? null),
                );
            }
            foreach ($pending as $item) {
                $lines[] = '- ' . $this->value($item['type'] ?? 'request') . ': ' . $this->value($item['user'] ?? null) . ' (не закрыто)';
            }
        }

        $mobilityItems = $mobilityItems->take(7);
        if ($mobilityItems->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Транспорт и ограничения';
            foreach ($mobilityItems as $item) {
                $summary = $this->value($item['summary'] ?? $item['impact'] ?? null);
                $line = $this->mobilityLine($item);
                if ($line !== null) {
                    $summary = preg_replace('/^'.preg_quote($line, '/').'\s*[—-]\s*/iu', '', $summary) ?: $summary;
                }
                $label = $line ?? $this->value($item['district'] ?? $item['title'] ?? null);
                $lines[] = '- ' . $label
                    . ' — ' . $summary;
            }
        }

        $actions = $this->morningActions($context);
        if ($actions !== []) {
            $lines[] = '';
            $lines[] = 'Действия на утро';
            foreach ($actions as $action) {
                $lines[] = '- ' . $action;
            }
        }

        $quality = $this->qualityLines($context['data_quality'] ?? []);
        if ($quality !== []) {
            $lines[] = '';
            $lines[] = 'Неполнота источников';
            array_push($lines, ...$quality);
        }

        if ($this->hasNoMeaningfulMorningData($context)) {
            $lines[] = '';
            $lines[] = 'За день значимых событий по доступным данным не обнаружено.';
        }

        return implode("\n", $lines);
    }

    public function evening(array $digest): string
    {
        $lines = [
            '🌙 Итоги дня',
            $this->dateLine($digest),
        ];

        $topics = collect($digest['forums'] ?? [])
            ->flatMap(fn (array $forum) => collect($forum['topics'] ?? [])->map(
                fn (array $topic): array => $topic + ['forum_title' => $forum['chat_title'] ?? null],
            ));

        $positive = $topics->filter(fn (array $topic): bool => (int) ($topic['positive_signals'] ?? 0) > 0 || ($topic['possible_resolved'] ?? false));
        $problems = $topics->filter(fn (array $topic): bool => (int) ($topic['problem_signals'] ?? 0) > 0);
        $unanswered = $topics->filter(fn (array $topic): bool => (bool) ($topic['possible_unanswered'] ?? false));
        $repeated = $topics->filter(fn (array $topic): bool => (bool) ($topic['repeated_problem'] ?? false));
        $resolved = $topics->filter(fn (array $topic): bool => (bool) ($topic['possible_resolved'] ?? false));

        $lines[] = '';
        $lines[] = 'Положительные моменты';
        if ($positive->isEmpty()) {
            $lines[] = '- Подтверждённых положительных сигналов не обнаружено.';
        } else {
            foreach ($positive->take(7) as $topic) {
                $suffix = ($topic['possible_resolved'] ?? false) ? '; возможно решено' : '';
                $lines[] = sprintf(
                    '- %s: %d положительных сигналов%s',
                    $this->topicLabel($topic),
                    (int) ($topic['positive_signals'] ?? 0),
                    $suffix,
                );
            }
        }

        if ($positive->isEmpty()) {
            array_splice($lines, -3);
        }

        $this->appendTopicSection($lines, 'Проблемы', $problems, fn (array $topic): string => sprintf(
            '%s: %d проблемных сигналов',
            $this->topicLabel($topic),
            (int) ($topic['problem_signals'] ?? 0),
        ));
        $this->appendTopicSection($lines, 'Без ответа', $unanswered, fn (array $topic): string => $this->topicLabel($topic));
        $this->appendTopicSection($lines, 'Повторяющиеся сигналы', $repeated, fn (array $topic): string => sprintf(
            '%s: %d проблемных сигналов',
            $this->topicLabel($topic),
            (int) ($topic['problem_signals'] ?? 0),
        ));
        $this->appendTopicSection($lines, 'Возможно решено', $resolved, fn (array $topic): string => $this->topicLabel($topic));

        $lines[] = '';
        $lines[] = 'Проверить завтра';
        if ($unanswered->isEmpty()) {
            $lines[] = '- Конкретных нерешённых вопросов не обнаружено.';
        } else {
            foreach ($unanswered->take(10) as $topic) {
                $lines[] = '- ' . $this->topicLabel($topic);
            }
        }

        if ($unanswered->isEmpty()) {
            array_splice($lines, -3);
        }

        $quality = $this->qualityLines($digest['data_quality'] ?? []);
        if ($quality !== []) {
            $lines[] = '';
            $lines[] = 'Качество данных';
            array_push($lines, ...$quality);
        }

        return implode("\n", $lines);
    }

    protected function morningActions(array $context): array
    {
        $actions = [];

        foreach ($context['risks'] ?? [] as $risk) {
            if (in_array($risk['code'] ?? null, ['mobility_alert', 'mobility_summary'], true)) {
                continue;
            }

            $actions[] = match ($risk['code'] ?? null) {
                'low_staffing', 'reduced_staffing' => 'Проверить покрытие смены при сниженной загрузке.',
                'peak_day' => 'Учесть пиковую дату при планировании.',
                'overdue_tasks' => 'Проверить просроченные задачи.',
                'critical_checks' => 'Проверить критические проверки.',
                'mobility_alert' => 'Уточнить влияние транспортного ограничения на смены.',
                'telegram_signals' => 'Проверить сигналы из рабочих чатов.',
                default => $this->value($risk['message'] ?? null),
            };
        }

        foreach ($context['tasks']['items'] ?? [] as $task) {
            if (empty($task['assignees']) && ! in_array($task['status'] ?? null, ['done', 'cancelled'], true)) {
                $actions[] = 'Назначить ответственного за задачу: ' . $this->value($task['title'] ?? null);
            }
        }

        return collect($actions)->filter()->unique()->take(7)->values()->all();
    }

    /**
     * Keep only current actionable mobility items and collapse competing
     * states for a line. This is presentation-only; sync/parser data remains
     * untouched.
     *
     * @param  array<string, mixed>  $context
     */
    protected function meaningfulMobilityItems(array $context): Collection
    {
        $date = (string) ($context['date'] ?? '');
        $items = collect($context['mobility']['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->filter(fn (array $item): bool => in_array(
                strtolower((string) ($item['risk'] ?? 'info')),
                ['critical', 'high', 'medium'],
                true,
            ))
            ->filter(fn (array $item): bool => $this->mobilityIsCurrent($item, $date))
            ->values();

        $lineItems = $items->filter(fn (array $item): bool => $this->mobilityLine($item) !== null);
        $otherItems = $items->reject(fn (array $item): bool => $this->mobilityLine($item) !== null);

        $selectedLines = $lineItems
            ->groupBy(fn (array $item): string => $this->mobilityLine($item) ?? '')
            ->map(fn (Collection $line): array => $line
                ->sort(function (array $left, array $right): int {
                    $leftFreshness = (string) ($left['updated_at'] ?? $left['created_at'] ?? $left['starts_at'] ?? '');
                    $rightFreshness = (string) ($right['updated_at'] ?? $right['created_at'] ?? $right['starts_at'] ?? '');
                    $freshness = strcmp($rightFreshness, $leftFreshness);

                    return $freshness !== 0
                        ? $freshness
                        : $this->mobilitySeverity($right) <=> $this->mobilitySeverity($left);
                })
                ->first())
            ->values();

        return $selectedLines
            ->concat($otherItems->unique(fn (array $item): string => implode('|', [
                $item['type'] ?? '',
                $item['district'] ?? '',
                $this->value($item['title'] ?? $item['summary'] ?? ''),
            ])))
            ->sortByDesc(fn (array $item): int => $this->mobilitySeverity($item))
            ->values()
            ->take(7);
    }

    /** @param array<string, mixed> $item */
    protected function mobilityIsCurrent(array $item, string $date): bool
    {
        if ($date === '') {
            return true;
        }

        try {
            $day = Carbon::parse($date)->startOfDay();
            $startsAt = ! empty($item['starts_at']) ? Carbon::parse((string) $item['starts_at'])->startOfDay() : null;
            $endsAt = ! empty($item['ends_at']) ? Carbon::parse((string) $item['ends_at'])->endOfDay() : null;

            return ($startsAt === null || $startsAt->lte($day->endOfDay()))
                && ($endsAt === null || $endsAt->gte($day));
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array<string, mixed> $item */
    protected function mobilityLine(array $item): ?string
    {
        $value = implode(' ', [
            (string) ($item['district'] ?? ''),
            (string) ($item['title'] ?? ''),
            (string) ($item['summary'] ?? ''),
        ]);

        return preg_match('/\b(M[1-5])\b/i', $value, $matches)
            ? strtoupper($matches[1])
            : null;
    }

    /** @param array<string, mixed> $item */
    protected function mobilitySeverity(array $item): int
    {
        return match (strtolower((string) ($item['risk'] ?? 'info'))) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 0,
        };
    }

    protected function appendTopicSection(array &$lines, string $title, Collection $topics, callable $formatter): void
    {
        if ($topics->isEmpty()) {
            return;
        }

        $lines[] = '';
        $lines[] = $title;
        foreach ($topics->take(7) as $topic) {
            $lines[] = '- ' . $formatter($topic);
        }
    }

    protected function topicLabel(array $topic): string
    {
        $forum = $this->value($topic['forum_title'] ?? null);
        $title = $this->value($topic['topic_title'] ?? null);

        $title = $title !== '' ? $title : 'Общая тема';

        return $forum !== '' ? $forum . ' / ' . $title : $title;
    }

    protected function qualityLines(array $quality): array
    {
        return collect($quality)
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['status'] ?? null) === 'unavailable')
            ->map(fn (mixed $item, string $source): string => '- ' . $source . ': ' . $this->value($item['reason'] ?? 'источник недоступен'))
            ->values()
            ->all();
    }

    protected function hasNoMeaningfulMorningData(array $context): bool
    {
        return empty($context['staff']['working'] ?? [])
            && empty($context['staff']['not_working'] ?? [])
            && empty($context['calendar']['events'] ?? [])
            && empty($context['tasks']['items'] ?? [])
            && $this->meaningfulMobilityItems($context)->isEmpty()
            && empty($context['risks'] ?? [])
            && (int) ($context['telegram']['messages'] ?? 0) === 0;
    }

    protected function dateLine(array $data): string
    {
        return sprintf(
            'Дата: %s | часовой пояс: %s',
            $this->value($data['date'] ?? null),
            $this->value($data['timezone'] ?? config('app.timezone', 'Europe/Rome')),
        );
    }

    protected function personLine(array $user): string
    {
        $name = $this->value($user['name'] ?? null);
        $reason = $this->value($user['reason'] ?? null);

        return $reason !== '' ? $name . ' — ' . $reason : $name;
    }

    protected function value(mixed $value): string
    {
        $value = preg_replace('/\s+/u', ' ', strip_tags((string) ($value ?? '')));

        return trim(mb_strimwidth($value ?: '', 0, 220, '…'));
    }
}

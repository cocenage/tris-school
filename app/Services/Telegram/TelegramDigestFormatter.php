<?php

namespace App\Services\Telegram;

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

        $risks = collect($context['risks'] ?? [])
            ->sortBy(fn (array $risk): int => match ($risk['level'] ?? 'info') {
                'critical' => 1,
                'high' => 2,
                'warning' => 3,
                default => 4,
            })
            ->values();

        if ($pending->isNotEmpty() || $risks->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Требует внимания';
            foreach ($risks->take(7) as $risk) {
                $lines[] = sprintf(
                    '- [%s] %s',
                    mb_strtoupper((string) ($risk['level'] ?? 'info')),
                    $this->value($risk['message'] ?? null),
                );
            }
            foreach ($pending as $item) {
                $lines[] = '- ' . $this->value($item['type'] ?? 'request') . ': ' . $this->value($item['user'] ?? null) . ' (не закрыто)';
            }
        }

        $mobilityItems = collect($context['mobility']['items'] ?? [])->take(7);
        if ($mobilityItems->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Транспорт и ограничения';
            foreach ($mobilityItems as $item) {
                $impact = ($item['impact'] ?? 'unknown') === 'unknown'
                    ? 'влияние на сотрудников не определено'
                    : (string) $item['impact'];
                $lines[] = sprintf(
                    '- [%s] %s — %s',
                    mb_strtoupper((string) ($item['risk'] ?? 'info')),
                    $this->value($item['title'] ?? null),
                    $this->value($impact),
                );
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
        $lines[] = 'Что прошло хорошо';
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
            && empty($context['mobility']['items'] ?? [])
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

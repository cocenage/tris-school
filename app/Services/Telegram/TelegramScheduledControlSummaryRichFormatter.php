<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

class TelegramScheduledControlSummaryRichFormatter
{
    public const FALLBACK_MAX_VISIBLE_CHARACTERS = 3500;

    public function __construct(private readonly TelegramRichMessageBuilder $richMessages) {}

    /** @return array{markdown: string} */
    public function richMessage(array $summary): array
    {
        $data = $this->data($summary);
        $lines = [
            '# 🧹 TRIS — Итоги рабочего дня',
            $this->richMessages->escapeRichText($data['date']).' · '.$data['control_count'].' проведённых контролей',
            '',
            '---',
            '',
        ];

        if ($data['problems'] === []) {
            $lines[] = 'Проблем по полученным ответам не выявлено.';
        }
        foreach ($this->problemGroups($data['problems']) as $label => $problems) {
            $lines[] = '## '.$label;
            foreach ($problems as $problem) {
                $lines[] = '';
                $lines[] = '**'.$this->richMessages->escapeRichText($problem['district'].' — '.$problem['control_label']).'** · '.$this->richMessages->escapeRichText($problem['status_label']);
                $lines[] = 'Контроль: '.$this->richMessages->escapeRichText($problem['control_time']).' · обнаружено: '.$this->richMessages->escapeRichText($problem['discovered_time']);
                $lines[] = 'Ответ: '.$this->richMessages->escapeRichText($problem['issue']);
                $lines[] = 'Квартира: '.$this->richMessages->escapeRichText($problem['apartment']).' · Причина: '.$this->richMessages->escapeRichText($problem['reason']).' · Автор: '.$this->richMessages->escapeRichText($problem['author']);
                if ($problem['repeat_summary'] !== '') {
                    $lines[] = 'Повторная проверка: '.$this->richMessages->escapeRichText($problem['repeat_summary']);
                }
                $lines[] = 'Итог: '.$this->richMessages->escapeRichText($problem['outcome']);
                if ($problem['source_url'] !== null) {
                    $lines[] = '[Исходное сообщение ↗]('.$problem['source_url'].')';
                }
                if ($problem['resolution_url'] !== null) {
                    $lines[] = '[Подтверждение ↗]('.$problem['resolution_url'].')';
                }
            }
            $lines[] = '';
        }

        $lines[] = '';
        if ($data['unanswered'] !== []) {
            $lines[] = '## Без ответа';
            $lines = [...$lines, ...array_map(fn (string $line): string => '- '.$this->richMessages->escapeRichText($line), $data['unanswered']), ''];
        }
        if ($data['not_applicable'] !== []) {
            $lines[] = '## Не применимо';
            $lines = [...$lines, ...array_map(fn (string $line): string => '- '.$this->richMessages->escapeRichText($line), $data['not_applicable']), ''];
        }
        $lines[] = '';
        $lines[] = '## 📋 Хронология';
        $lines = [...$lines, ...array_map(fn (string $item): string => '- '.$this->richMessages->escapeRichText($item), $data['chronology'] ?: ['Значимых изменений с зафиксированным временем нет.'])];

        $lines[] = '';
        $lines[] = '## 📊 Итоги дня';
        $lines[] = '| Показатель | Значение |';
        $lines[] = '|:--|--:|';
        foreach ($data['metrics'] as $label => $value) {
            $lines[] = '| '.$this->richMessages->escapeRichText($label).' | '.$value.' |';
        }

        foreach ($data['details'] as $detail) {
            if ($detail['lines'] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '<details><summary>'.$this->richMessages->escapeRichText($detail['title']).'</summary>';
            $lines[] = '';
            foreach ($detail['lines'] as $line) {
                $lines[] = '- '.$this->richMessages->escapeRichText($line['text']).($line['url'] !== null ? ' [↗]('.$line['url'].')' : '');
            }
            $lines[] = '</details>';
        }

        return $this->richMessages->markdownDocument(implode("\n", $lines));
    }

    /** A compact sendMessage fallback, leaving room below Telegram's 4096-character text limit. */
    public function fallbackHtml(array $summary): string
    {
        $data = $this->data($summary);
        $metrics = $data['metrics'];
        $lines = [
            '<b>🧹 TRIS — Итоги рабочего дня</b>',
            $this->html($data['date']).' · контрольных сообщений: '.$data['control_count'],
            'Открыто: '.$metrics['Открытых проблем'].' · устранено: '.$metrics['Подтверждённых устранений']
                .' · ответы расходятся: '.$metrics['Противоречивых ответов'],
        ];

        $groups = array_map(fn (array $problems): array => array_map($this->problemLine(...), $problems),
            $this->problemGroups($data['problems']));
        if ($data['unanswered'] !== []) {
            $groups['Без ответа · точек: '.$metrics['Контрольных точек без ответа']] = array_map(
                fn (string $line): string => '• '.$this->html($this->compactText($line, 200)), $data['unanswered']);
        }
        if ($data['pending_points'] > 0) {
            $groups['Окно ответа ещё открыто'] = ['Точек: '.$data['pending_points']];
        }
        if ($data['not_applicable'] !== []) {
            $groups['Не применимо'] = array_map(
                fn (string $line): string => '• '.$this->html($this->compactText($line, 200)), $data['not_applicable']);
        }
        $technical = $data['technical_counts'];
        if (array_sum($technical) > 0) {
            $groups['Доставка контролей'] = ['Не доставлено: '.$technical['failed']
                .' · доставка не подтверждена: '.$technical['uncertain'].' · в очереди: '.$technical['pending']];
        }

        $groups = array_filter($groups);
        if ($groups === []) {
            $lines[] = 'По полученным ответам отрицательных результатов нет.';
        }
        $room = intdiv(self::FALLBACK_MAX_VISIBLE_CHARACTERS - 300, max(1, count($groups)));
        foreach ($groups as $label => $rows) {
            $lines[] = '';
            $lines[] = '<b>'.$this->html($label).'</b>';
            $used = 0;
            $shown = 0;
            foreach ($rows as $row) {
                $used += TelegramRichMessageBuilder::htmlVisibleLength($row) + 1;
                if ($used > $room - 48) {
                    break;
                }
                $lines[] = $row;
                $shown++;
            }
            if ($shown < count($rows)) {
                $lines[] = '… ещё записей: '.(count($rows) - $shown).'.';
            }
        }

        return implode("\n", $lines);
    }

    private function problemGroups(array $problems): array
    {
        $labels = [
            'problem' => 'Ответили «нет»',
            'partial' => 'Выполнено частично',
            'unclear' => 'Устранение не подтверждено',
            'confirmed' => 'Подтвердили устранение',
            'conflict' => 'Ответы расходятся',
        ];
        $groups = array_fill_keys($labels, []);
        foreach ($problems as $problem) {
            $groups[$labels[$problem['status']]][] = $problem;
        }

        return array_filter($groups);
    }

    private function problemLine(array $problem): string
    {
        $line = '• <b>'.$this->html($this->compactText($problem['district'].' — '.$problem['control_label'], 100)).'</b>';
        $context = array_filter([$problem['discovered_time'], $problem['apartment']], fn (string $value): bool => $value !== 'Не установлено');
        if ($context !== []) {
            $line .= ' · '.$this->html($this->compactText(implode(' · ', $context), 72));
        }
        $description = $problem['reason'] !== 'Не установлено' ? $problem['reason'] : $problem['issue'];
        $line .= ' — '.$this->html($this->compactText($description, 104));
        if ($problem['source_url'] !== null) {
            $line .= ' · <a href="'.$problem['source_url'].'">'.($problem['has_negative'] && $problem['initial_status'] === 'problem' ? 'Ответ «нет»' : 'Ответ').'</a>';
        }
        if ($problem['resolution_url'] !== null) {
            $line .= ' → <a href="'.$problem['resolution_url'].'">Подтверждение'.($problem['resolution_time'] !== 'Не установлено' ? ' в '.$problem['resolution_time'] : '').'</a>';
        } elseif ($problem['status'] === 'confirmed') {
            $line .= ' → '.$this->html($problem['outcome']);
        }

        return $line;
    }

    private function compactText(string $value, int $maxCharacters): string
    {
        $value = trim((string) (preg_replace('/[\p{Cc}\s]+/u', ' ', $value) ?? $value));
        if (mb_strlen($value, 'UTF-8') <= $maxCharacters) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxCharacters - 1, 'UTF-8')).'…';
    }

    private function data(array $summary): array
    {
        $rows = collect($summary['deliveries'] ?? [])->values();
        $rowsById = $rows->keyBy(fn (array $row): string => (string) ($row['delivery_id'] ?? ''));
        $stories = [];

        foreach ($rows as $row) {
            if (! is_array($row['district_history'] ?? null)) {
                continue;
            }
            foreach ($row['district_history'] as $district) {
                $key = (string) ($district['district_key'] ?? $district['district'] ?? 'unknown');
                $family = $this->controlFamilyForRow($row);
                $root = $this->sequenceRoot($row, $rowsById);
                $storyKey = implode('|', [$root, $family, $key]);
                $stories[$storyKey] ??= [
                    'district' => (string) ($district['district'] ?? 'Район не установлен'),
                    'district_key' => $key,
                    'control_types' => [],
                    'scheduled_times' => [],
                    'base_statuses' => [],
                    'events' => [],
                    'sources' => [],
                    'followups' => [],
                ];
                $stories[$storyKey]['control_types'][] = (string) ($row['control_type'] ?? '');
                $stories[$storyKey]['scheduled_times'][] = (string) ($row['scheduled_for'] ?? '');
                $stories[$storyKey]['base_statuses'][] = (string) ($district['status'] ?? 'unknown');
                foreach (($district['followups'] ?? []) as $followup) {
                    $stories[$storyKey]['followups'][] = $followup;
                }
                if (filled($row['source_url'] ?? null)) {
                    $stories[$storyKey]['sources'][] = ['text' => 'Контрольное сообщение · '.$this->controlLabel((string) ($row['control_type'] ?? '')), 'url' => $row['source_url']];
                }

                $timeline = $district['timeline'] ?? [];
                if ($timeline === [] && filled($district['first_problem_at'] ?? null)) {
                    $timeline[] = [
                        'status' => in_array($district['status'] ?? null, ['problem', 'partial'], true) ? $district['status'] : 'problem',
                        'responded_at' => $district['first_problem_at'],
                    ];
                }
                foreach ($timeline as $event) {
                    $url = $this->safeSourceUrl($event['source_url'] ?? null);
                    $stories[$storyKey]['events'][] = $event + [
                        'control_type' => $row['control_type'] ?? '',
                        'control_time' => $row['scheduled_for'] ?? '',
                        'delivery_id' => $row['delivery_id'] ?? null,
                        'source_url' => $url,
                    ];
                    if ($url !== null) {
                        $stories[$storyKey]['sources'][] = ['text' => 'Ответ · '.$stories[$storyKey]['district'], 'url' => $url];
                    }
                }
            }
        }

        $stories = array_map(fn (array $story): array => $this->finalizeStory($story), $stories);
        $problems = array_values(array_filter($stories, fn (array $story): bool => $story['first_problem'] !== null || $story['status'] === 'conflict'));
        usort($problems, fn (array $left, array $right): int => strcmp(
            (string) ($left['first_problem']['responded_at'] ?? ''),
            (string) ($right['first_problem']['responded_at'] ?? ''),
        ));

        $completedByControl = [];
        foreach ($stories as $story) {
            if ($story['status'] !== 'confirmed' || $story['first_problem'] !== null) {
                continue;
            }
            $label = $this->controlLabel($story['control_types'][0] ?? '');
            $completedByControl[$label][$story['district']] = true;
        }
        $completed = [];
        foreach ($completedByControl as $label => $districts) {
            $completed[] = $label.': '.implode(', ', array_keys($districts));
        }

        $chronology = $this->chronology($stories);
        $totals = $summary['totals'] ?? [];
        $sent = (int) ($totals['sent'] ?? $rows->where('delivery_status', 'sent')->count());
        $problemCount = count($problems);
        $repeatCount = array_sum(array_column($problems, 'repeat_count'));
        $resolved = count(array_filter($problems, fn (array $story): bool => $story['status'] === 'confirmed'));
        $open = count(array_filter($problems, fn (array $story): bool => in_array($story['status'], ['problem', 'partial', 'unclear'], true)));
        $conflicts = count(array_filter($stories, fn (array $story): bool => $story['status'] === 'conflict'));
        $unanswered = $this->pointGroups($rows, 'no_response');
        $notApplicable = $this->pointGroups($rows, 'not_applicable');
        $noAnswer = array_sum(array_map('count', $unanswered));

        $metrics = [
            'Контрольных сообщений' => $sent,
            'Уникальных проблем' => $problemCount,
            'Повторных проблемных фиксаций' => $repeatCount,
            'Подтверждённых устранений' => $resolved,
            'Открытых проблем' => $open,
            'Противоречивых ответов' => $conflicts,
            'Контрольных точек без ответа' => $noAnswer,
        ];

        return [
            'date' => $this->displayDate((string) ($summary['date'] ?? '')),
            'control_count' => $sent,
            'unanswered' => $this->pointLabels($unanswered),
            'pending_points' => array_sum(array_map('count', $this->pointGroups($rows, 'pending'))),
            'not_applicable' => $this->pointLabels($notApplicable),
            'technical_counts' => [
                'failed' => (int) ($totals['not_delivered'] ?? 0),
                'uncertain' => (int) ($totals['uncertain_deliveries'] ?? 0),
                'pending' => (int) ($totals['pending_deliveries'] ?? 0),
            ],
            'problems' => array_map(fn (array $story): array => $this->problemView($story), $problems),
            'chronology' => $chronology,
            'metrics' => $metrics,
            'details' => [
                ['title' => '✅ Что выполнено', 'lines' => array_map(fn (string $text): array => ['text' => $text, 'url' => null], $completed)],
                ['title' => '🔍 Полные ответы по контрольным точкам', 'lines' => $this->answerDetails($rows)],
                ['title' => '🔁 История повторных проверок', 'lines' => $this->historyDetails($stories)],
                ['title' => '🔗 Источники', 'lines' => $this->sourceDetails($stories)],
                ['title' => '🛠 Техническая диагностика рассылки', 'lines' => $this->technicalDetails($summary, $rows)],
            ],
        ];
    }

    private function finalizeStory(array $story): array
    {
        $events = collect($story['events'])->unique(fn (array $event): string => implode('|', [
            (string) ($event['response_id'] ?? $event['source_message_id'] ?? ''),
            (string) ($event['responded_at'] ?? ''), (string) ($event['status'] ?? ''),
            (string) ($event['text'] ?? ''), (string) ($event['control_type'] ?? ''),
        ]))->sortBy(fn (array $event): string => (string) ($event['responded_at'] ?? ''))->values();
        $states = [];
        $firstProblem = null;
        $resolvedAt = null;
        $conflictAt = null;
        $problemEventKeys = [];

        foreach ($events as $index => $event) {
            $status = (string) ($event['status'] ?? 'unclear');
            $identity = (string) ($event['responder_key'] ?? 'event:'.($event['response_id'] ?? $event['source_message_id'] ?? $index));
            $previous = $states[$identity] ?? null;
            if ($status !== 'unclear' || ! in_array($previous, ['problem', 'partial'], true)) {
                $states[$identity] = $status;
            }
            if (in_array($status, ['problem', 'partial'], true)) {
                $firstProblem ??= $event;
                $resolvedAt = null;
                $problemEventKeys[(string) ($event['response_id'] ?? $event['source_message_id'] ?? $index)] = true;
            }
            $values = collect($states)->unique();
            $hasBad = $values->contains(fn (string $value): bool => in_array($value, ['problem', 'partial'], true));
            $hasConfirmed = $values->contains('confirmed');
            $applicabilityConflict = $values->contains('not_applicable') && $values->count() > 1;
            if (($hasBad && $hasConfirmed) || $applicabilityConflict) {
                $conflictAt = $event;
                $resolvedAt = null;
            } elseif ($firstProblem !== null && $states !== [] && collect($states)->every(fn (string $value): bool => $value === 'confirmed')) {
                $resolvedAt = $event['responded_at'] ?? null;
                $conflictAt = null;
            }
        }

        $values = collect($states)->values();
        $hasBad = $values->contains(fn (string $value): bool => in_array($value, ['problem', 'partial'], true));
        $hasConfirmed = $values->contains('confirmed');
        $status = match (true) {
            $hasBad && $hasConfirmed => 'conflict',
            $values->contains('not_applicable') && $values->count() > 1 => 'conflict',
            $values->contains('problem') => 'problem',
            $values->contains('partial') => 'partial',
            $values->contains('not_applicable') => 'not_applicable',
            $values->contains('unclear') => 'unclear',
            $values->isNotEmpty() => 'confirmed',
            in_array('conflict', $story['base_statuses'], true) => 'conflict',
            in_array('problem', $story['base_statuses'], true) => 'problem',
            in_array('partial', $story['base_statuses'], true) => 'partial',
            in_array('unclear', $story['base_statuses'], true) => 'unclear',
            in_array('confirmed', $story['base_statuses'], true) => 'confirmed',
            default => 'no_response',
        };

        $story['events'] = $events->all();
        $story['status'] = $status;
        $story['first_problem'] = $firstProblem;
        $story['resolved_at'] = $status === 'confirmed' && $firstProblem !== null ? $resolvedAt : null;
        $story['conflict_at'] = $status === 'conflict' ? $conflictAt : null;
        $story['repeat_count'] = max(0, count($problemEventKeys) - ($firstProblem === null ? 0 : 1));
        $story['control_types'] = array_values(array_unique(array_filter($story['control_types'])));
        $story['scheduled_times'] = array_values(array_filter($story['scheduled_times']));
        $story['sources'] = collect($story['sources'])->unique('url')->values()->all();
        $story['followups'] = array_values($story['followups']);

        return $story;
    }

    private function problemView(array $story): array
    {
        $first = $story['first_problem'] ?? $story['conflict_at'] ?? [];
        $bad = collect($story['events'])->filter(fn (array $event): bool => in_array($event['status'] ?? null, ['problem', 'partial'], true))->last() ?? $first;
        $controlType = (string) ($first['control_type'] ?? $story['control_types'][0] ?? '');
        $rechecks = collect($story['events'])->filter(fn (array $event): bool => $event !== $first
            && (string) ($event['responded_at'] ?? '') > (string) ($first['responded_at'] ?? ''));
        $repeatSummary = $rechecks->map(function (array $event): string {
            $controlTime = $this->time((string) ($event['control_time'] ?? ''));
            $responseTime = $this->time((string) ($event['responded_at'] ?? ''));
            $time = $controlTime !== 'Не установлено' && $controlTime !== $responseTime
                ? 'контроль '.$controlTime.', ответ '.$responseTime
                : $responseTime;

            return $time.' — '.$this->statusShort((string) ($event['status'] ?? 'unclear'));
        })
            ->filter(fn (string $item): bool => ! str_starts_with($item, ' — '))->implode('; ');
        $reason = collect($story['events'])->filter(fn (array $event): bool => in_array($event['status'] ?? null, ['problem', 'partial'], true))
            ->pluck('reason')->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->last();
        $apartment = collect($story['events'])->filter(fn (array $event): bool => in_array($event['status'] ?? null, ['problem', 'partial'], true))
            ->pluck('apartment')->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->last();
        $author = $bad['author_name'] ?? null;
        if (! filled($author) && filled($bad['username'] ?? null)) {
            $author = '@'.ltrim((string) $bad['username'], '@');
        }
        $source = $this->safeSourceUrl($bad['source_url'] ?? null);
        $resolution = $story['resolved_at'] === null ? null : $rechecks->last(fn (array $event): bool => ($event['status'] ?? null) === 'confirmed' && ($event['responded_at'] ?? null) === $story['resolved_at']);

        return [
            'district' => $story['district'],
            'control_label' => $this->controlLabel($controlType),
            'control_time' => $this->time((string) ($first['control_time'] ?? $story['scheduled_times'][0] ?? '')),
            'discovered_time' => $this->time((string) ($first['responded_at'] ?? '')),
            'issue' => filled($first['text'] ?? null) ? (string) $first['text'] : 'Не выполнено; подробности в ответе не указаны',
            'apartment' => filled($apartment) ? (string) $apartment : 'Не установлено',
            'reason' => filled($reason) ? (string) $reason : 'Не установлено',
            'author' => filled($author) ? (string) $author : 'Не установлено',
            'repeat_summary' => $repeatSummary,
            'outcome' => $this->outcome($story),
            'status' => $story['status'],
            'has_negative' => $story['first_problem'] !== null,
            'initial_status' => $bad['status'] ?? null,
            'resolution_url' => $this->safeSourceUrl($resolution['source_url'] ?? null),
            'resolution_time' => $this->time((string) ($story['resolved_at'] ?? '')),
            'status_label' => match ($story['status']) {
                'confirmed' => 'устранено и подтверждено',
                'conflict' => 'Противоречивые ответы · требует уточнения',
                'partial' => 'не завершено полностью',
                'unclear' => 'нет ясного подтверждения устранения',
                default => 'открытая проблема',
            },
            'source_url' => $source,
        ];
    }

    private function chronology(array $stories): array
    {
        $events = [];
        foreach ($stories as $story) {
            if ($story['first_problem'] !== null) {
                $controlTime = $story['first_problem']['control_time'] ?? null;
                $responseTime = $story['first_problem']['responded_at'] ?? null;
                $events[] = [
                    'at' => $controlTime ?: $responseTime,
                    'text' => $story['district'].' — проблема обнаружена'.($controlTime && $responseTime && $this->time((string) $controlTime) !== $this->time((string) $responseTime)
                        ? ' (ответ в '.$this->time((string) $responseTime).')' : ''),
                ];
            } elseif ($story['status'] === 'confirmed') {
                $last = collect($story['events'])->last();
                if ($last !== null && filled($last['responded_at'] ?? null)) {
                    $at = $last['control_time'] ?? $last['responded_at'];
                    $events[] = ['at' => $at, 'text' => $story['district'].' — контроль завершён'.($this->time((string) $at) !== $this->time((string) $last['responded_at'])
                        ? ' (ответ в '.$this->time((string) $last['responded_at']).')' : '')];
                }
            }
            if ($story['resolved_at'] !== null) {
                $resolution = collect($story['events'])->last(fn (array $event): bool => ($event['responded_at'] ?? null) === $story['resolved_at']);
                $at = $resolution['control_time'] ?? $story['resolved_at'];
                $events[] = ['at' => $at, 'text' => $story['district'].' — устранение подтверждено'.($this->time((string) $at) !== $this->time((string) $story['resolved_at'])
                    ? ' (ответ в '.$this->time((string) $story['resolved_at']).')' : '')];
            }
            if ($story['conflict_at'] !== null) {
                $controlTime = $story['conflict_at']['control_time'] ?? null;
                $responseTime = $story['conflict_at']['responded_at'] ?? null;
                $events[] = ['at' => $controlTime ?: $responseTime, 'text' => $story['district'].' — противоречивые ответы'.($controlTime && $responseTime && $this->time((string) $controlTime) !== $this->time((string) $responseTime)
                    ? ' (ответ в '.$this->time((string) $responseTime).')' : '')];
            }
        }
        $events = collect($events)->filter(fn (array $event): bool => filled($event['at']))
            ->sortBy('at')->unique(fn (array $event): string => (string) $event['at'].'|'.$event['text'])->values();

        return $events->map(fn (array $event): string => $this->time((string) $event['at']).' — '.$event['text'])
            ->filter(fn (string $line): bool => ! str_starts_with($line, ' — '))->all();
    }

    private function answerDetails($rows): array
    {
        $details = [];
        foreach ($rows as $row) {
            foreach (($row['interpreted_responses'] ?? []) as $response) {
                $author = $response['author_name'] ?? null;
                if (! filled($author) && filled($response['username'] ?? null)) {
                    $author = '@'.ltrim((string) $response['username'], '@');
                }
                $districts = collect($response['districts'] ?? [])->pluck('district')->filter()->unique()->implode(', ');
                $prefix = $this->time((string) ($response['responded_at'] ?? $row['scheduled_for'] ?? ''))
                    .' · '.$this->controlLabel((string) ($row['control_type'] ?? ''));
                if ($districts !== '') {
                    $prefix .= ' · '.$districts;
                }
                $prefix .= ' · '.(filled($author) ? $author : 'Автор: Не установлено');
                $text = (string) ($response['full_text'] ?? $response['text'] ?? 'Ответ без текста');
                $details[] = ['text' => $prefix.': '.$text, 'url' => $this->safeSourceUrl($response['source_url'] ?? null)];
            }
        }

        return $details;
    }

    private function historyDetails(array $stories): array
    {
        $details = [];
        foreach ($stories as $story) {
            if ($story['events'] === []) {
                continue;
            }
            foreach ($story['events'] as $event) {
                $author = $event['author_name'] ?? null;
                if (! filled($author) && filled($event['username'] ?? null)) {
                    $author = '@'.ltrim((string) $event['username'], '@');
                }
                $text = filled($event['text'] ?? null) ? ': '.(string) $event['text'] : '';
                $details[] = [
                    'text' => $this->time((string) ($event['responded_at'] ?? '')).' · '.$story['district'].' · '.$this->statusShort((string) ($event['status'] ?? 'unclear'))
                        .(filled($author) ? ' · '.$author : ' · Автор: Не установлено').$text,
                    'url' => $this->safeSourceUrl($event['source_url'] ?? null),
                ];
            }
        }

        return $details;
    }

    private function sourceDetails(array $stories): array
    {
        return collect($stories)->flatMap(fn (array $story): array => $story['sources'])
            ->unique('url')->map(fn (array $source): array => ['text' => $source['text'], 'url' => $this->safeSourceUrl($source['url'])])
            ->filter(fn (array $source): bool => $source['url'] !== null)->values()->all();
    }

    private function technicalDetails(array $summary, $rows): array
    {
        $details = [];
        foreach ($rows as $row) {
            $status = (string) ($row['delivery_status'] ?? 'sent');
            if ($status !== 'sent') {
                $details[] = ['text' => $this->time((string) ($row['scheduled_for'] ?? '')).' · '.$this->controlLabel((string) ($row['control_type'] ?? '')).': '.$this->deliveryStatus($status, $row), 'url' => null];
            }
            foreach (($row['district_history'] ?? []) as $district) {
                foreach (($district['followups'] ?? []) as $followup) {
                    $followupStatus = (string) ($followup['status'] ?? 'unknown');
                    if ($followupStatus !== 'sent') {
                        $details[] = ['text' => $district['district'].' · повторная проверка: '.$this->deliveryStatus($followupStatus, $followup), 'url' => null];
                    }
                }
            }
        }
        foreach (($summary['missing_deliveries'] ?? []) as $missing) {
            $details[] = [
                'text' => $this->time((string) ($missing['scheduled_for'] ?? '')).' · '.$this->controlLabel((string) ($missing['control_type'] ?? '')).': '.(($missing['configuration_uncertain'] ?? false)
                    ? 'доставка не подтверждена из-за неполной истории настроек'
                    : 'отправка не зафиксирована'),
                'url' => null,
            ];
        }

        if ($details === []) {
            $details[] = ['text' => 'Ошибок и неопределённых результатов доставки не зафиксировано.', 'url' => null];
        }

        return $details;
    }

    private function deliveryStatus(string $status, array $row): string
    {
        return match ($status) {
            'uncertain', 'sending' => 'результат отправки неизвестен',
            'failed' => 'ошибка доставки подтверждена',
            'pending', 'queued', 'retrying' => 'ожидает отправки или повтора',
            'cancelled' => ($row['condition_not_met'] ?? false) ? 'отменено: условие не выполнено' : 'отменено',
            'skipped' => 'пропущено по условию',
            default => 'статус: '.$status,
        };
    }

    private function pointGroups($rows, string $status): array
    {
        $points = [];
        foreach ($rows as $row) {
            if (($row['delivery_status'] ?? null) !== 'sent' || ($row['eligible_for_statistics'] ?? true) === false) {
                continue;
            }
            $label = $this->controlLabel((string) ($row['control_type'] ?? ''));
            foreach (($row['district_history'] ?? []) as $district) {
                if (($district['status'] ?? null) === $status) {
                    $time = $this->time((string) ($row['scheduled_for'] ?? ''));
                    $points[$district['district'] ?? 'Район не установлен'][] = $label.($time === 'Не установлено' ? '' : ' · '.$time);
                }
            }
        }

        return $points;
    }

    private function pointLabels(array $points): array
    {
        return array_map(fn (string $district, array $labels): string => $district.' — '.implode(', ', $labels),
            array_keys($points), array_values($points));
    }

    private function chronologyTime(?string $value): ?string
    {
        return filled($value) ? $this->time($value) : null;
    }

    private function sequenceRoot(array $row, $rowsById): string
    {
        $current = (string) ($row['delivery_id'] ?? 'unknown');
        $visited = [];
        while (isset($rowsById[(string) ($rowsById[$current]['trigger_delivery_id'] ?? '')])) {
            $parent = (string) $rowsById[$current]['trigger_delivery_id'];
            if (isset($visited[$parent])) {
                break;
            }
            $visited[$parent] = true;
            $current = $parent;
        }

        return $current;
    }

    private function controlFamily(string $type): string
    {
        return match ($type) {
            'first_cleanings_started', 'first_cleanings_finishing' => 'first_cleaning',
            'second_cleanings_finishing' => 'second_cleaning',
            'couriers_completed' => 'courier',
            'extra_payments_completed' => 'payment',
            'schedule_checked' => 'schedule',
            'cleanings_completed' => 'cleanings_completed',
            default => $type,
        };
    }

    private function controlFamilyForRow(array $row): string
    {
        return match ($row['sequence_context']['pair'] ?? null) {
            'first_cleanings' => 'first_cleaning',
            'second_cleanings' => 'second_cleaning',
            'courier_deliveries' => 'courier',
            default => $this->controlFamily((string) ($row['control_type'] ?? '')),
        };
    }

    private function controlLabel(string $type): string
    {
        return match ($type) {
            'first_cleanings_started' => 'первые уборки · начало',
            'first_cleanings_finishing' => 'первые уборки · повторная проверка',
            'second_cleanings_finishing' => 'вторые уборки',
            'cleanings_completed' => 'завершение уборок',
            'couriers_completed' => 'доставки курьеров',
            'extra_payments_completed' => 'доплаты',
            'schedule_checked' => 'расписание и заметки',
            default => $type !== '' ? $type : 'контрольная точка',
        };
    }

    private function statusShort(string $status): string
    {
        return match ($status) {
            'confirmed', 'ok' => 'подтверждено',
            'problem' => 'не выполнено',
            'partial' => 'выполнено частично',
            'unclear', 'unknown' => 'ответ неясен',
            'not_applicable' => 'не применимо',
            default => 'ответ: '.$status,
        };
    }

    private function outcome(array $story): string
    {
        return match ($story['status']) {
            'confirmed' => $story['resolved_at'] !== null ? 'устранение подтверждено в '.$this->time((string) $story['resolved_at']) : 'выполнение подтверждено; прежняя проблема не зафиксирована',
            'conflict' => 'ответы противоречат друг другу; требуется уточнение',
            'partial' => 'часть работ остаётся незавершённой',
            'unclear' => 'устранение не подтверждено',
            default => 'проблема остаётся открытой',
        };
    }

    private function time(string $value): string
    {
        if (! filled($value)) {
            return 'Не установлено';
        }
        try {
            return CarbonImmutable::parse($value, 'Europe/Rome')->setTimezone('Europe/Rome')->format('H:i');
        } catch (\Throwable) {
            return 'Не установлено';
        }
    }

    private function displayDate(string $value): string
    {
        try {
            return CarbonImmutable::parse($value, 'Europe/Rome')->format('d.m.Y');
        } catch (\Throwable) {
            return 'Дата не установлена';
        }
    }

    private function safeSourceUrl(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https://t\.me/c/\d{1,20}/\d{1,20}$~D', $value) === 1 ? $value : null;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

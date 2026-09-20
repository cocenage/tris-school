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
                    ? ' из '.(int) $context['staff']['shift']['total'].' назначенных'
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
                $lines[] = '- '.$absence;
            }
        }

        $events = collect($context['calendar']['events'] ?? []);
        $peakEvents = $events->filter(fn (array $event): bool => ($event['type'] ?? null) === 'peak');
        $otherEvents = $events->reject(fn (array $event): bool => ($event['type'] ?? null) === 'peak')->take(7);

        if ($peakEvents->isNotEmpty() || $otherEvents->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Календарь';
            foreach ($peakEvents->take(7) as $event) {
                $lines[] = '- '.$this->value($event['title'] ?? null).' (пиковая дата)';
            }
            foreach ($otherEvents as $event) {
                $lines[] = '- '.$this->value($event['title'] ?? null);
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
                'message' => '🚇 '.$mobilityCriticalCount.' существенных транспортных ограничений',
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
                $lines[] = '- '.$this->value($item['type'] ?? 'request').': '.$this->value($item['user'] ?? null).' (не закрыто)';
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
                $lines[] = '- '.$label
                    .' — '.$summary;
            }
        }

        $actions = $this->morningActions($context);
        if ($actions !== []) {
            $lines[] = '';
            $lines[] = 'Действия на утро';
            foreach ($actions as $action) {
                $lines[] = '- '.$action;
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
                $lines[] = '- '.$this->topicLabel($topic);
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

    public function eveningIntelligence(array $preview): string
    {
        $district = $this->value($preview['district']['label'] ?? null);
        $eligibleItems = collect($preview['sections'] ?? [])
            ->flatMap(fn (array $section) => $section['items'] ?? [])
            ->unique(fn (array $item): string => (string) ($item['event_key'] ?? sha1(json_encode($item))))
            ->filter(fn (array $item): bool => $this->isHumanEveningEvent($item))
            ->values();
        $positiveItems = $eligibleItems
            ->filter(fn (array $item): bool => $this->isPositiveEveningEvent($item))
            ->take(7)
            ->values();
        $otherItems = $eligibleItems
            ->reject(fn (array $item): bool => $this->isPositiveEveningEvent($item))
            ->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'resolved'
                || $this->hasEveningTransitionOnDay($item, 'resolved', $preview))
            ->values();
        $items = $this->selectEveningItems($otherItems
            ->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'resolved'
                || $this->hasEveningTransitionOnDay($item, 'created', $preview))
            ->values(), 6);
        $resolvedItems = $otherItems
            ->filter(fn (array $item): bool => ($item['status'] ?? null) === 'resolved')
            ->take(4)
            ->values();
        $openQuestions = $otherItems
            ->filter(fn (array $item): bool => $this->isOpenEveningQuestion($item));
        $openLines = $openQuestions->concat($items)
            ->unique(fn (array $item): string => (string) ($item['event_key'] ?? sha1(json_encode($item))))
            ->filter(fn (array $item): bool => in_array($item['status'] ?? null, ['open', 'reopened'], true))
            ->map(function (array $item): ?string {
                $followUp = $this->humanEveningFollowUp($item);

                return $followUp === null ? null : $this->withEveningContext($item, $followUp);
            })
            ->filter()
            ->unique()
            ->take(4)
            ->values();
        $lines = [
            '🌙 '.($district !== '' ? $district : 'TRIS').' — итоги дня',
        ];

        if ($items->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'За день:';
            foreach ($items as $item) {
                $lines[] = '• '.$this->withEveningContext($item, $this->humanEveningSummary($item));
            }
        } elseif ($resolvedItems->isEmpty() && $positiveItems->isEmpty()) {
            $lines[] = '';
            $lines[] = 'За день:';
            $lines[] = '• Значимых операционных событий не зафиксировано.';
        }

        if ($resolvedItems->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '✅ Решено сегодня:';

            foreach ($resolvedItems as $item) {
                $lines[] = '• '.$this->withEveningContext($item, $this->humanEveningResolution($item));
            }
        }

        if ($positiveItems->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⭐ Хорошая работа:';

            foreach ($positiveItems as $item) {
                $lines[] = '• '.$this->withEveningContext($item, $this->humanPositiveSummary($item));
            }
        }

        $lines[] = '';
        if ($openLines->isEmpty()) {
            $lines[] = 'Открытых вопросов на конец дня нет.';
        } else {
            $lines[] = 'Осталось на контроле:';
            foreach ($openLines as $line) {
                $lines[] = '• '.$line;
            }
        }

        return implode("\n", $lines);
    }

    private function hasEveningTransitionOnDay(array $item, string $transition, array $preview): bool
    {
        $date = (string) ($preview['date'] ?? '');

        if ($date === '') {
            return true;
        }

        return collect($item['evidence'] ?? [])->contains(function (array $evidence) use ($transition, $date, $preview): bool {
            if (($evidence['transition'] ?? null) !== $transition || blank($evidence['occurred_at'] ?? null)) {
                return false;
            }

            return Carbon::parse($evidence['occurred_at'])
                ->setTimezone($preview['timezone'] ?? config('app.timezone', 'Europe/Rome'))
                ->toDateString() === $date;
        });
    }

    private function isPositiveEveningEvent(array $item): bool
    {
        $types = collect($item['types'] ?? []);

        return $types->contains('positive_contribution')
            && $types->diff(['positive_contribution'])->isEmpty();
    }

    private function humanPositiveSummary(array $item): string
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));
        $actor = $this->value($item['actor_name'] ?? null);

        if ($actor !== '' && preg_match('/^я\s+/ui', $summary) === 1) {
            $summary = preg_replace('/^я\s+/ui', '', $summary) ?: $summary;

            return trim(mb_strimwidth($actor.' '.$this->sentence($summary), 0, 160, '…'));
        }

        return trim(mb_strimwidth(mb_ucfirst($this->sentence($summary)), 0, 160, '…'));
    }

    private function selectEveningItems(Collection $items, int $limit): Collection
    {
        $remaining = $items->values();
        $selected = collect();
        $typeOrder = [
            'problem', 'quality_issue', 'delay', 'unanswered_question', 'risk',
            'request', 'commitment', 'action', 'positive_contribution', 'resolution',
        ];

        while ($remaining->isNotEmpty() && $selected->count() < $limit) {
            $selectedInRound = false;

            foreach ($typeOrder as $type) {
                $index = $remaining->search(fn (array $item): bool => in_array($type, $item['types'] ?? [], true));

                if ($index === false) {
                    continue;
                }

                $selected->push($remaining->get($index));
                $remaining->forget($index);
                $selectedInRound = true;

                if ($selected->count() >= $limit) {
                    break 2;
                }
            }

            if (! $selectedInRound) {
                $selected->push($remaining->shift());
            }

            $remaining = $remaining->values();
        }

        return $selected->values();
    }

    private function isHumanEveningEvent(array $item): bool
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));

        if ($summary === '') {
            return false;
        }

        if (preg_match('/^(?:готово|сделано|решено|исправлено|ok|ок)[.!]*$/iu', $summary)) {
            return false;
        }

        return ! preg_match(
            '/(?:\bинструкция\b|\bшаблон\b|\bалгоритм\b|\bделаем\s+\d+(?:-\d+)?\s+фото\b|^\s*при осмотре .+\b(?:делаем|сделайте|необходимо)\b|\?\s*(?:нужно|необходимо)\b.+\bчтобы\b)/iu',
            $summary,
        );
    }

    private function humanEveningSummary(array $item): string
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));
        $types = collect($item['types'] ?? []);

        $text = match (true) {
            $this->isOpenEveningQuestion($item) => $this->questionSummary($summary),
            $this->isDoorNotOpening($summary) => 'Возникла проблема с доступом.',
            $this->isAccessProblem($summary) => $this->problemSummary($summary),
            $this->bathroomIssue($summary) !== null => $this->bathroomIssue($summary),
            $this->isLinenStorageIssue($summary) => 'Возник вопрос с хранением грязного и чистого белья.',
            $types->contains('delay') => $this->delaySummary($summary, $this->value($item['actor_name'] ?? null)),
            $types->contains('quality_issue') => $this->qualitySummary($summary),
            $types->contains('unanswered_question') => $this->questionSummary($summary),
            $types->contains('risk') => 'Отмечен риск: '.$this->sentence($summary),
            $types->contains('problem') => $this->problemSummary($summary),
            $types->contains('positive_contribution') => 'Отмечен полезный вклад: '.$this->sentence($summary),
            default => mb_ucfirst($this->sentence($summary)),
        };

        return trim(mb_strimwidth($text, 0, 160, '…'));
    }

    private function humanEveningFollowUp(array $item): ?string
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));
        $types = collect($item['types'] ?? []);

        $text = match (true) {
            $this->isOpenEveningQuestion($item) => $this->questionFollowUp($summary) ?? 'Есть открытый вопрос, требующий уточнения.',
            $this->isDoorNotOpening($summary) => 'Проверить, решён ли вопрос с доступом в квартиру.',
            $this->isAccessProblem($summary) => 'Проверить, решён ли вопрос с доступом в квартиру.',
            $this->bathroomIssue($summary) !== null => null,
            $this->isLinenStorageIssue($summary) => null,
            $types->contains('unanswered_question') => $this->questionFollowUp($summary),
            $types->contains('quality_issue') => $this->qualityFollowUp($summary),
            $types->contains('problem') => $this->problemFollowUp($summary),
            default => null,
        };

        return $text === null ? null : trim(mb_strimwidth($text, 0, 160, '…'));
    }

    private function humanEveningResolution(array $item): string
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));
        $types = collect($item['types'] ?? []);

        return match (true) {
            $this->isDoorNotOpening($summary), $this->isAccessProblem($summary) => 'проблема с доступом решена.',
            preg_match('/замок/iu', $summary) === 1 => 'проблема с замком решена.',
            $types->contains('quality_issue') => 'замечание по качеству устранено.',
            $types->contains('unanswered_question') => 'вопрос закрыт.',
            $types->contains('delay') => 'ситуация с задержкой закрыта.',
            default => 'ситуация решена.',
        };
    }

    private function isDoorNotOpening(string $summary): bool
    {
        return preg_match('/(?:не\s+открыва\S*\s+двер|двер\S*\s+не\s+открыва)/iu', $summary) === 1;
    }

    private function withEveningContext(array $item, string $text): string
    {
        $context = $this->value($item['context_label'] ?? null);

        return $context !== '' ? $context.' — '.$text : $text;
    }

    private function cleanEveningSummary(string $summary): string
    {
        $summary = preg_replace('/\s+/u', ' ', strip_tags($summary)) ?: '';
        $summary = preg_replace('/(?:^|\s)@[\p{L}\p{N}_]+\b/u', '', $summary) ?: $summary;
        $summary = preg_replace('/^#\S+\s+/u', '', trim($summary)) ?: '';
        $summary = str_ireplace(['клмплект', 'прогоамме'], ['комплект', 'программе'], $summary);
        $summary = preg_replace('/^(?:привет|здравствуйте|доброе\s+утро|добрый\s+день|добрый\s+вечер)[,!\.\s]+/iu', '', $summary) ?: $summary;
        $summary = preg_replace('/^(?:девочки|коллеги)[,!]?\s*(?:подскажите|скажите)(?:,?\s*пожалуйста)?[,]?\s*/iu', '', $summary) ?: $summary;
        $summary = preg_replace('/^[\p{Lu}][\p{Ll}]{2,20}[,!:]\s*(?=(?:во\s+сколько|где|когда|есть\s+ли|для\s+\S+|можно\s+ли|сколько|что\s+делать|подскажи))/u', '', $summary) ?: $summary;
        $summary = preg_replace('/^(?:подскажите|скажите)(?:,?\s*пожалуйста)?[,]?\s*/iu', '', $summary) ?: $summary;
        $summary = preg_replace('/^(?:ну|эм|ээ)[,!\.]+\s*/iu', '', $summary) ?: $summary;

        return trim(preg_replace('/\s+/u', ' ', $summary) ?: $summary);
    }

    private function questionSummary(string $summary): string
    {
        if ($this->isBrokenGlassesQuestion($summary)) {
            return 'Уточняли, что делать со сломанными очками.';
        }

        if ($this->isSofaBeddingQuestion($summary)) {
            return 'Уточняли наличие постельного белья для дивана.';
        }

        if ($this->isSparePaperQuestion($summary)) {
            return preg_match('/не\s+могу\s+найти|не\s+наш/iu', $summary)
                ? 'Не смогли найти запасную бумагу.'
                : 'Уточняли наличие запасной бумаги.';
        }

        if ($this->isArrivalTimeQuestion($summary)) {
            return 'Уточняли время заезда.';
        }

        if (preg_match('/^сколько (?:им )?времени нужно/iu', $summary)) {
            return 'Уточняли, сколько времени потребуется.';
        }

        return 'Уточняли: '.$this->sentence($summary);
    }

    private function questionFollowUp(string $summary): ?string
    {
        if ($this->isBrokenGlassesQuestion($summary)) {
            return 'Уточнить, нужно ли выбрасывать сломанные очки.';
        }

        if ($this->isSofaBeddingQuestion($summary)) {
            return 'Уточнить наличие постельного белья для дивана.';
        }

        if ($this->isSparePaperQuestion($summary)) {
            return 'Проверить наличие запасной бумаги.';
        }

        if ($this->isArrivalTimeQuestion($summary)) {
            return 'Уточнить время заезда.';
        }

        if (preg_match('/^сколько (?:им )?времени нужно/iu', $summary)) {
            return 'Нужно уточнить, сколько времени потребуется.';
        }

        return null;
    }

    private function isOpenEveningQuestion(array $item): bool
    {
        $summary = $this->cleanEveningSummary((string) ($item['summary'] ?? ''));

        return in_array($item['status'] ?? null, ['open', 'reopened'], true)
            && (in_array('unanswered_question', $item['types'] ?? [], true)
                || collect($item['evidence'] ?? [])->contains(fn (array $evidence): bool => ($evidence['role'] ?? null) === 'question')
                || str_contains($summary, '?')
                || $this->isSparePaperQuestion($summary));
    }

    private function isArrivalTimeQuestion(string $summary): bool
    {
        return preg_match('/\bво\s+сколько\b.{0,40}\bзаезд\b|\bзаезд\b.{0,40}\bво\s+сколько\b/iu', $summary) === 1;
    }

    private function isSofaBeddingQuestion(string $summary): bool
    {
        return preg_match('/диван/iu', $summary) === 1
            && preg_match('/постельн|бель[еёя]/iu', $summary) === 1
            && preg_match('/\?|есть|налич|шкаф/iu', $summary) === 1;
    }

    private function isSparePaperQuestion(string $summary): bool
    {
        return preg_match('/запасн.{0,20}бумаг|бумаг.{0,20}запасн/iu', $summary) === 1
            && preg_match('/не\s+могу\s+найти|не\s+наш|есть|налич|\d+\s+или\s+\d+|\?/iu', $summary) === 1;
    }

    private function isBrokenGlassesQuestion(string $summary): bool
    {
        return preg_match('/очк/iu', $summary) === 1
            && preg_match('/слом|поврежд/iu', $summary) === 1
            && preg_match('/выбрасыва|выкидыва|что\s+делать/iu', $summary) === 1;
    }

    private function delaySummary(string $summary, string $actor = ''): string
    {
        if (preg_match('/(?:на|\b)\s*(\d{1,3})\s*мин/iu', $summary, $matches)) {
            return $actor !== ''
                ? $actor.': задержка примерно на '.(int) $matches[1].' минут.'
                : 'Сотрудник сообщил о задержке примерно на '.(int) $matches[1].' минут.';
        }

        return $actor !== '' ? $actor.': задержка.' : 'Сотрудник сообщил о задержке.';
    }

    private function qualitySummary(string $summary): string
    {
        if (preg_match('/^брак был в прошлой уборке/iu', $summary)) {
            return 'Обнаружен брак, ранее отмеченный как загрязнение.';
        }

        if (preg_match('/^брак[\s\x{2011}\x{2013}\x{2014}-]*(.+)$/iu', $summary, $matches)) {
            return 'Обнаружен брак '.rtrim($this->sentence($matches[1]), '.').'.';
        }

        return mb_ucfirst($this->sentence($summary));
    }

    private function qualityFollowUp(string $summary): ?string
    {
        if (preg_match('/полотенц/iu', $summary)) {
            return 'Проверить замену бракованного полотенца.';
        }

        return null;
    }

    private function problemSummary(string $summary): string
    {
        if (preg_match('/^ч[её]т\s+не\s+открыва/iu', $summary)) {
            return 'Возникла проблема: что-то не открывается.';
        }

        if ($this->isAccessProblem($summary)) {
            $scope = preg_match('/квартир/iu', $summary) ? ' с доступом в квартиру' : ' с доступом';

            return 'Возникла проблема'.$scope.': дверь была закрыта, никто не открыл.';
        }

        if (preg_match('/вытяжка не работает на кухне/iu', $summary)) {
            return 'На кухне не работала вытяжка.';
        }

        if (preg_match('/в спальне не откры\S* ставн/iu', $summary)) {
            return 'В спальне не открывалась ставня.';
        }

        if (preg_match('/чистый только один комплект.+курьера? ещ[\x{0451}е] не было/iu', $summary)) {
            return 'Оставался один чистый комплект; доставка ещё не приехала.';
        }

        $summary = preg_replace('/^(?:возникла?\s+)?проблема\s*[:\-]?\s*/iu', '', $summary) ?: $summary;

        return mb_ucfirst($this->sentence($summary));
    }

    private function problemFollowUp(string $summary): ?string
    {
        if ($this->isAccessProblem($summary)) {
            return 'Проверить, решён ли вопрос с доступом в квартиру.';
        }

        if (preg_match('/вытяжка не работает на кухне/iu', $summary)) {
            return 'Нужно проверить, работает ли вытяжка на кухне.';
        }

        if (preg_match('/в спальне не откры\S* ставн/iu', $summary)) {
            return 'Нужно проверить, открывается ли ставня в спальне.';
        }

        if (preg_match('/чистый только один комплект/iu', $summary)) {
            return 'Нужно подтвердить, что чистые комплекты доставлены.';
        }

        return null;
    }

    private function isAccessProblem(string $summary): bool
    {
        return preg_match('/(?:двер[ьи].*закрыт|закрыт.*двер[ьи])/iu', $summary) === 1
            && preg_match('/никто\s+не\s+откр(?:ыл|ывает)/iu', $summary) === 1;
    }

    private function bathroomIssue(string $summary): ?string
    {
        if (! preg_match('/ванн/iu', $summary)) {
            return null;
        }

        $damage = preg_match('/поврежден|повреждён|сломано|поломк/iu', $summary) === 1;
        $dirt = preg_match('/гряз|загрязн/iu', $summary) === 1;

        return match (true) {
            $damage && $dirt => 'В ванной обнаружили повреждение или загрязнение.',
            $damage => 'В ванной обнаружили повреждение.',
            $dirt => 'В ванной обнаружили загрязнение.',
            default => null,
        };
    }

    private function isLinenStorageIssue(string $summary): bool
    {
        return preg_match('/грязн/iu', $summary) === 1
            && preg_match('/чист/iu', $summary) === 1
            && preg_match('/шкаф/iu', $summary) === 1;
    }

    private function sentence(string $value): string
    {
        $value = trim($value);

        return rtrim(mb_lcfirst($value), " \t\n\r\0\x0B.!?").'.';
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
                $actions[] = 'Назначить ответственного за задачу: '.$this->value($task['title'] ?? null);
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
            $lines[] = '- '.$formatter($topic);
        }
    }

    protected function topicLabel(array $topic): string
    {
        $forum = $this->value($topic['forum_title'] ?? null);
        $title = $this->value($topic['topic_title'] ?? null);

        $title = $title !== '' ? $title : 'Общая тема';

        return $forum !== '' ? $forum.' / '.$title : $title;
    }

    protected function qualityLines(array $quality): array
    {
        return collect($quality)
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['status'] ?? null) === 'unavailable')
            ->map(fn (mixed $item, string $source): string => '- '.$source.': '.$this->value($item['reason'] ?? 'источник недоступен'))
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

    protected function evidenceReferences(array $evidence): string
    {
        $references = collect($evidence)
            ->map(fn (array $item): string => sprintf(
                '#%s/%s',
                $this->value($item['local_message_id'] ?? null),
                $this->value($item['telegram_message_id'] ?? null),
            ))
            ->filter()
            ->values();

        if ($references->count() <= 5) {
            return $references->implode(', ');
        }

        return $references->take(5)->implode(', ').' +'.($references->count() - 5);
    }

    protected function personLine(array $user): string
    {
        $name = $this->value($user['name'] ?? null);
        $reason = $this->value($user['reason'] ?? null);

        return $reason !== '' ? $name.' — '.$reason : $name;
    }

    protected function value(mixed $value): string
    {
        $value = preg_replace('/\s+/u', ' ', strip_tags((string) ($value ?? '')));

        return trim(mb_strimwidth($value ?: '', 0, 220, '…'));
    }
}

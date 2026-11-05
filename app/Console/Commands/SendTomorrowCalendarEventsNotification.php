<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\CalendarSummaryService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDigestBuildJournal;
use App\Services\Telegram\TelegramOutboundMonitor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class SendTomorrowCalendarEventsNotification extends Command
{
    protected $signature = 'calendar:notify-tomorrow {--dry-run}';

    protected $description = 'Send Telegram notification with tomorrow calendar summary';

    public function handle(
        CalendarEventsService $calendarEventsService,
        CalendarSummaryService $calendarSummaryService,
        TelegramBotService $telegramBotService,
        TelegramDigestBuildJournal $journal,
    ): int {
        $timezone = config('app.timezone', 'Europe/Rome');

        Carbon::setLocale('ru');

        $tomorrow = now($timezone)->addDay()->startOfDay();

        $chatId = config('services.telegram.chat_id_calendar');
        $threadId = config('services.telegram.thread_id_calendar');
        $context = [
            'operation_key' => 'calendar:tomorrow:'.$tomorrow->toDateString(),
            'notification_type' => 'calendar', 'bot' => 'work',
            'chat_id' => $chatId ? (string) $chatId : null, 'message_thread_id' => $threadId,
            'source_type' => 'CalendarDate', 'source_id' => $tomorrow->toDateString(),
        ];
        $journalContext = $this->option('dry-run') ? null : $context;

        if ($journalContext !== null) {
            $journal->reserve($journalContext);
        }

        try {
            $sourceEvents = collect($calendarEventsService->getEventsForDay($tomorrow, 'peak'));
        } catch (Throwable $exception) {
            if ($journalContext !== null) {
                $journal->record($journalContext, 'calendar_source', 'source_error');
            }

            throw $exception;
        }

        if ($journalContext !== null) {
            $journal->record(
                $journalContext,
                'calendar_source',
                $sourceEvents->isEmpty() ? 'no_input' : 'loaded',
                ['input_count' => $sourceEvents->count()],
            );
        }

        $events = $sourceEvents->where('type', 'peak')->values();
        if ($journalContext !== null) {
            $includedCount = $events->count();
            $journal->record($journalContext, 'calendar_filter', $includedCount > 0 ? 'complete' : 'no_significant_events', [
                'input_count' => $sourceEvents->count(),
                'filtered_count' => $sourceEvents->count() - $includedCount,
                'included_count' => $includedCount,
            ]);
        }

        try {
            $summary = $calendarSummaryService->build($tomorrow);
        } catch (Throwable $exception) {
            if ($journalContext !== null) {
                $journal->record($journalContext, 'calendar_source', 'source_error');
            }

            throw $exception;
        }

        try {
            $message = $this->buildMessage($tomorrow, $events, $summary);
        } catch (Throwable $exception) {
            if ($journalContext !== null) {
                $journal->record($journalContext, 'calendar_format', 'calculation_error');
            }

            throw $exception;
        }

        if ($journalContext !== null) {
            $messagePresent = trim($message) !== '';
            $journal->record($journalContext, 'calendar_format', $messagePresent ? 'built' : 'no_message', [
                'message_present' => $messagePresent,
            ]);
        }

        if ($this->option('dry-run')) {
            $this->line($message);

            return self::SUCCESS;
        }

        if (! $chatId) {
            $this->error('Не настроен services.telegram.chat_id_calendar');

            return self::FAILURE;
        }

        $monitor = app(TelegramOutboundMonitor::class);
        $monitor->withContext($context, fn () => $telegramBotService->sendMessage(
            chatId: $chatId,
            text: $message,
            threadId: $threadId,
        ));

        $this->info('Уведомление отправлено.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, Collection $events, array $summary): string
    {
        $lines = [];

        $lines[] = '📅 <b>Сводка на завтра</b>';
        $dateLabel = $date->translatedFormat('l, j F Y');
        $lines[] = '<b>'.e(mb_strtoupper(mb_substr($dateLabel, 0, 1)).mb_substr($dateLabel, 1)).'</b>';
        $lines[] = '';

        $this->appendShiftBlock($lines, $summary);
        $this->appendStaffingBlock($lines, $summary);
        $peaks = $events->where('type', 'peak')->values();

        if ($peaks->isNotEmpty()) {
            $this->appendPeakBlock($lines, $peaks);
        }

        return trim(implode("\n", $lines));
    }

    public function renderStaffSummary(Carbon $date, array $summary): string
    {
        return $this->buildMessage($date, collect(), $summary);
    }

    protected function appendShiftBlock(array &$lines, array $summary): void
    {
        $shift = $summary['shift'] ?? null;

        if (! $shift) {
            return;
        }

        $lines[] = match ($shift['level'] ?? null) {
            'good' => '🟢 Смена: лёгкая',
            'warning' => '🟡 Смена: средняя',
            'critical' => '🔴 Смена: тяжёлая',
            'unknown' => (int) ($shift['total'] ?? 0) > 0
                ? 'ℹ️ Смена: график клинеров подтверждён не полностью'
                : 'ℹ️ Смена: нет данных',
            default => 'ℹ️ Смена: нет данных',
        };
    }

    protected function appendNotWorkingBlock(array &$lines, array $summary): void
    {
        $notWorking = collect(data_get($summary, 'workers.not_working', []))
            ->map(function ($user) {
                data_set($user, 'normalized_reason', $this->normalizeNotWorkingReason(
                    data_get($user, 'not_working_reason', 'Не работает')
                ));

                return $user;
            })
            ->sortBy(fn ($user) => mb_strtolower((string) data_get($user, 'name', '')))
            ->values();

        if ($notWorking->isEmpty()) {
            return;
        }

        $grouped = $notWorking->groupBy(fn ($user) => $this->detectUserRoleGroup($user));

        $groups = [
            'supervisor' => '👔 <b>Супервайзеры</b>',
            'cleaner' => '🧹 <b>Клинеры</b>',
            'other' => '👤 <b>Остальные</b>',
        ];

        $lines[] = '🚫 <b>Кто не работает</b>';
        $lines[] = '';

        foreach ($groups as $group => $title) {
            $users = $grouped->get($group, collect());

            if ($users->isEmpty()) {
                continue;
            }

            $lines[] = $title.' — <b>'.$users->count().'</b>';

            foreach ($users as $user) {
                $name = data_get($user, 'name', 'Без имени');
                $reason = data_get($user, 'normalized_reason', 'Не работает');

                $lines[] = '• '.e($name).' — '.e($reason);
            }
        }

        $lines[] = '';
    }

    protected function appendStaffingBlock(array &$lines, array $summary): void
    {
        $roles = [
            'cleaner' => [
                'title' => '🧹 <b>Клинеры</b>',
                'all_available' => 'Все клинеры доступны',
            ],
            'supervisor' => [
                'title' => '👔 <b>Супервайзеры</b>',
            ],
        ];

        foreach ($roles as $role => $labels) {
            $staff = data_get($summary, "workers.by_role.{$role}");
            $total = (int) data_get($staff, 'total', 0);

            if ($total < 1) {
                $lines[] = $labels['title'].': нет данных о составе';
                $lines[] = '';

                continue;
            }

            $available = (int) data_get($staff, 'available_count', 0);
            $unknown = (int) data_get($staff, 'unknown_count', 0);
            $availablePeople = collect(data_get($staff, 'available', []))
                ->sortBy(fn (array $person) => mb_strtolower((string) ($person['name'] ?? '')))
                ->values();
            $absent = collect(data_get($staff, 'absent', []))
                ->sortBy(fn (array $person) => mb_strtolower((string) ($person['name'] ?? '')))
                ->values();

            $lines[] = $labels['title'].' — доступно: <b>'.$available.' из '.$total.'</b>';

            if ($unknown > 0) {
                $lines[] = 'График не подтверждён у <b>'.$unknown.'</b>'.($unknown === 1 ? ' сотрудника' : ' сотрудников');
            }

            if ($role === 'supervisor') {
                $people = $availablePeople
                    ->map(fn (array $person) => [
                        'name' => (string) ($person['name'] ?? 'Без имени'),
                        'status' => 'working',
                    ])
                    ->concat($absent->map(fn (array $person) => [
                        'name' => (string) ($person['name'] ?? 'Без имени'),
                        'status' => (string) ($person['reason'] ?? 'unknown'),
                    ]))
                    ->concat(collect(data_get($staff, 'unknown', []))->map(fn (array $person) => [
                        'name' => (string) ($person['name'] ?? 'Без имени'),
                        'status' => 'schedule_unknown',
                    ]))
                    ->sortBy(fn (array $person) => mb_strtolower($person['name']))
                    ->values();

                foreach ($people as $person) {
                    [$indicator, $status] = match ($person['status']) {
                        'working' => ['✅', 'работает'],
                        'regular_weekend' => ['💤', 'рег. выходной'],
                        'day_off' => ['💤', 'выходной'],
                        'vacation' => ['🌴', 'отпуск'],
                        'unpaid_leave' => ['💤', 'неоплачиваемый отпуск'],
                        'schedule_unknown' => ['ℹ️', 'график не подтверждён'],
                        default => ['💤', 'отсутствует'],
                    };

                    $lines[] = $indicator.' '.e($person['name']).' — '.$status;
                }
            } else {
                if ($absent->isEmpty()) {
                    if ($unknown === 0) {
                        $lines[] = $labels['all_available'];
                    } else {
                        foreach (collect(data_get($staff, 'unknown', [])) as $person) {
                            $lines[] = '• '.e((string) ($person['name'] ?? 'Без имени')).' — график не подтверждён';
                        }
                    }
                    $lines[] = '';

                    continue;
                }

                $lines[] = 'Не работают: <b>'.(int) data_get($staff, 'absent_count', $absent->count()).'</b>';

                foreach ($absent as $person) {
                    $reason = match ($person['reason'] ?? null) {
                        'vacation' => 'отпуск',
                        'day_off' => 'выходной',
                        'regular_weekend' => 'регулярный выходной',
                        'unpaid_leave' => 'неоплачиваемый отпуск',
                        default => 'отсутствие без уточнённой категории',
                    };

                    $lines[] = '• '.e($person['name'] ?? 'Без имени').' — '.$reason;
                }
            }

            $lines[] = '';
        }
    }

    protected function appendPeakBlock(array &$lines, Collection $events): void
    {
        $lines[] = '🔥 <b>Пики загрузки</b>';
        $lines[] = 'Всего: <b>'.$events->count().'</b>';
        $lines[] = '';

        foreach ($events as $event) {
            $lines[] = $this->formatTelegramEventLine($event);
        }
    }

    protected function appendNotWorkingEvents(Collection $events, array $summary): Collection
    {
        $notWorking = collect(data_get($summary, 'workers.not_working', []));

        if ($notWorking->isEmpty()) {
            return $events->values();
        }

        $extraEvents = $notWorking->map(function ($user) {
            $name = data_get($user, 'name', 'Без имени');
            $rawReason = data_get($user, 'not_working_reason', 'Не работает');
            $reason = $this->normalizeNotWorkingReason($rawReason);

            return [
                'type' => $this->detectNotWorkingType($rawReason),
                'title' => $name.' — '.$reason,
                'description' => null,
                'start' => null,
                'end' => null,
                'source' => 'summary_not_working',
            ];
        });

        return $events
            ->concat($extraEvents)
            ->unique(fn (array $event) => implode('|', [
                $event['type'] ?? 'other',
                $event['title'] ?? '',
                optional($event['start'] ?? null)->toDateString(),
                optional($event['end'] ?? null)->toDateString(),
            ]))
            ->values();
    }

    protected function removeDuplicatedNotWorkingEvents(Collection $events, array $summary): Collection
    {
        $notWorkingNames = collect(data_get($summary, 'workers.not_working', []))
            ->map(fn ($user) => mb_strtolower((string) data_get($user, 'name')))
            ->filter()
            ->values();

        if ($notWorkingNames->isEmpty()) {
            return $events->values();
        }

        return $events
            ->reject(function (array $event) use ($notWorkingNames) {
                $type = $event['type'] ?? 'other';
                $title = mb_strtolower((string) ($event['title'] ?? ''));

                if (! in_array($type, ['day_off', 'vacation', 'sick'], true)) {
                    return false;
                }

                return $notWorkingNames->contains(
                    fn (string $name) => $name !== '' && str_contains($title, $name)
                );
            })
            ->values();
    }

    protected function detectUserRoleGroup($user): string
    {
        $role = mb_strtolower((string) (
            data_get($user, 'role')
            ?? data_get($user, 'role_name')
            ?? data_get($user, 'position')
            ?? data_get($user, 'type')
            ?? ''
        ));

        if (
            str_contains($role, 'supervisor') ||
            str_contains($role, 'супер') ||
            str_contains($role, 'супервайзер')
        ) {
            return 'supervisor';
        }

        if (
            str_contains($role, 'cleaner') ||
            str_contains($role, 'клинер') ||
            str_contains($role, 'убор')
        ) {
            return 'cleaner';
        }

        return 'other';
    }

    protected function detectNotWorkingType(string $reason): string
    {
        $reason = mb_strtolower($reason);

        if (
            str_contains($reason, 'отпуск') ||
            str_contains($reason, 'vacation') ||
            str_contains($reason, 'ferie')
        ) {
            return 'vacation';
        }

        if (
            str_contains($reason, 'больн') ||
            str_contains($reason, 'sick') ||
            str_contains($reason, 'malatt')
        ) {
            return 'sick';
        }

        return 'day_off';
    }

    protected function normalizeNotWorkingReason(string $reason): string
    {
        $lower = mb_strtolower(trim($reason));

        if (
            str_contains($lower, 'отпуск') ||
            str_contains($lower, 'vacation') ||
            str_contains($lower, 'ferie')
        ) {
            return 'отпуск';
        }

        if (
            str_contains($lower, 'выход') ||
            str_contains($lower, 'day off') ||
            str_contains($lower, 'riposo')
        ) {
            return 'выходной';
        }

        if (
            str_contains($lower, 'больн') ||
            str_contains($lower, 'sick') ||
            str_contains($lower, 'malatt')
        ) {
            return 'больничный';
        }

        return 'не работает';
    }

    protected function formatTelegramEventLine(array $event): string
    {
        $lines = [];

        $lines[] = '• <b>'.e($event['title'] ?? 'Без названия').'</b>';

        $range = $this->formatTelegramEventRange($event);

        if ($range) {
            $lines[] = e($range);
        }

        if (! empty($event['description'])) {
            $description = mb_strimwidth(trim($event['description']), 0, 180, '...');
            $lines[] = '<blockquote>'.e($description).'</blockquote>';
        }

        return implode("\n", $lines);
    }

    protected function formatTelegramEventRange(array $event): ?string
    {
        if (empty($event['start']) || empty($event['end'])) {
            return null;
        }

        $start = $event['start']->copy()->startOfDay();
        $end = $event['end']->copy()->startOfDay();

        if ($start->isSameDay($end)) {
            return null;
        }

        if ($start->year === $end->year && $start->month === $end->month) {
            return $start->translatedFormat('j').'–'.$end->translatedFormat('j F Y');
        }

        if ($start->year === $end->year) {
            return $start->translatedFormat('j F').' — '.$end->translatedFormat('j F Y');
        }

        return $start->translatedFormat('j F Y').' — '.$end->translatedFormat('j F Y');
    }

    protected function eventTypeOrder(): array
    {
        return [
            'holiday',
            'birthday',
            'anniversary',
            'work_anniversary',
            'day_off',
            'vacation',
            'sick',
            'tasks',
            'workflow',
            'finance',
            'peak',
            'strike',
            'other',
        ];
    }

    protected function typeIcon(string $type): string
    {
        return match ($type) {
            'tasks' => '⚡️',
            'workflow' => '🏢',
            'finance' => '💸',
            'holiday' => '🎉',
            'birthday' => '🎂',
            'anniversary', 'work_anniversary' => '🏆',
            'peak' => '🔥',
            'day_off' => '🌿',
            'vacation' => '🏖',
            'sick' => '🤒',
            'strike' => '⚠️',
            default => '📎',
        };
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'tasks' => 'Задачи',
            'workflow' => 'Рабочие процессы',
            'finance' => 'Финансы',
            'holiday' => 'Праздники',
            'birthday' => 'Дни рождения',
            'anniversary', 'work_anniversary' => 'Годовщины',
            'peak' => 'Пики загрузки',
            'day_off' => 'Выходные',
            'vacation' => 'Отпуска',
            'sick' => 'Больничные',
            'strike' => 'Забастовки',
            'other' => 'Другое',
            default => str($type)->replace('_', ' ')->ucfirst()->toString(),
        };
    }
}

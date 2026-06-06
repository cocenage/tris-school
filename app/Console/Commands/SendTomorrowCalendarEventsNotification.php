<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\CalendarSummaryService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendTomorrowCalendarEventsNotification extends Command
{
    protected $signature = 'calendar:notify-tomorrow {--dry-run}';

    protected $description = 'Send Telegram notification with tomorrow calendar summary';

    public function handle(
        CalendarEventsService $calendarEventsService,
        CalendarSummaryService $calendarSummaryService,
        TelegramBotService $telegramBotService,
    ): int {
        $timezone = config('app.timezone', 'Europe/Stockholm');

        Carbon::setLocale('ru');

        $tomorrow = now($timezone)->addDay()->startOfDay();

        $events = collect($calendarEventsService->getEventsForDay($tomorrow));
        $summary = $calendarSummaryService->build($tomorrow);

        $events = $this->appendNotWorkingEvents($events, $summary);

        $message = $this->buildMessage($tomorrow, $events, $summary);

        if ($this->option('dry-run')) {
            $this->line($message);

            return self::SUCCESS;
        }

        $chatId = config('services.telegram.chat_id_calendar');
        $threadId = config('services.telegram.thread_id_calendar');

        if (! $chatId) {
            $this->error('Не настроен services.telegram.chat_id_calendar');

            return self::FAILURE;
        }

        $telegramBotService->sendMessage(
            chatId: $chatId,
            text: $message,
            threadId: $threadId,
        );

        $this->info('Уведомление отправлено.');

        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, Collection $events, array $summary): string
    {
        $lines = [];

        $lines[] = '📅 <b>Сводка на завтра</b>';
        $lines[] = '<b>' . e(mb_convert_case($date->translatedFormat('l, j F Y'), MB_CASE_TITLE, 'UTF-8')) . '</b>';
        $lines[] = '';

        $this->appendShiftBlock($lines, $summary);
        $this->appendNotWorkingBlock($lines, $summary);

        $events = $this->removeDuplicatedNotWorkingEvents($events, $summary);
        $grouped = $events->groupBy(fn (array $event) => $event['type'] ?? 'other');

        if ($events->isNotEmpty()) {
            $this->appendEventsBlock($lines, $events, $grouped);
        }

        return trim(implode("\n", $lines));
    }

    protected function appendShiftBlock(array &$lines, array $summary): void
    {
        $shift = $summary['shift'] ?? null;

        if (! $shift) {
            return;
        }

        $lines[] = '👥 <b>Смена</b>';
        $lines[] = 'Работают: <b>' . ($shift['working'] ?? 0) . '/' . ($shift['total'] ?? 0) . '</b>';
        $lines[] = 'Не работают: <b>' . ($shift['not_working'] ?? 0) . '</b>';
        $lines[] = 'Статус: <b>' . e($shift['label'] ?? 'Без статуса') . '</b>';
        $lines[] = '';
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

        foreach ($groups as $group => $title) {
            $users = $grouped->get($group, collect());

            if ($users->isEmpty()) {
                continue;
            }

            $lines[] = $title . ' — <b>' . $users->count() . '</b>';

            foreach ($users as $user) {
                $name = data_get($user, 'name', 'Без имени');
                $reason = data_get($user, 'normalized_reason', 'Не работает');

                $lines[] = '• ' . e($name) . ' — ' . e($reason);
            }
        }

        $lines[] = '';
    }

    protected function appendEventsBlock(array &$lines, Collection $events, Collection $grouped): void
    {
        $lines[] = '📌 <b>События</b>';
        $lines[] = 'Всего событий: <b>' . $events->count() . '</b>';
        $lines[] = '';

        $knownTypes = $this->eventTypeOrder();

        $unknownTypes = $grouped
            ->keys()
            ->reject(fn ($type) => in_array($type, $knownTypes, true))
            ->sort()
            ->values()
            ->all();

        $typesToRender = [
            ...$knownTypes,
            ...$unknownTypes,
        ];

        foreach ($typesToRender as $type) {
            $items = $grouped->get($type, collect());

            if ($items->isEmpty()) {
                continue;
            }

            $lines[] = $this->typeIcon($type) . ' <b>' . e($this->typeLabel($type)) . '</b>';

            foreach ($items as $event) {
                $lines[] = $this->formatTelegramEventLine($event);
            }

            $lines[] = '';
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
                'title' => $name . ' — ' . $reason,
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
            return 'Отпуск';
        }

        if (
            str_contains($lower, 'выход') ||
            str_contains($lower, 'day off') ||
            str_contains($lower, 'riposo')
        ) {
            return 'Выходной';
        }

        if (
            str_contains($lower, 'больн') ||
            str_contains($lower, 'sick') ||
            str_contains($lower, 'malatt')
        ) {
            return 'Больничный';
        }

        return 'Не работает';
    }

    protected function formatTelegramEventLine(array $event): string
    {
        $lines = [];

        $lines[] = '• <b>' . e($event['title'] ?? 'Без названия') . '</b>';

        $range = $this->formatTelegramEventRange($event);

        if ($range) {
            $lines[] = e($range);
        }

        if (! empty($event['description'])) {
            $description = mb_strimwidth(trim($event['description']), 0, 180, '...');
            $lines[] = '<blockquote>' . e($description) . '</blockquote>';
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
            return $start->translatedFormat('j') . '–' . $end->translatedFormat('j F Y');
        }

        if ($start->year === $end->year) {
            return $start->translatedFormat('j F') . ' — ' . $end->translatedFormat('j F Y');
        }

        return $start->translatedFormat('j F Y') . ' — ' . $end->translatedFormat('j F Y');
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
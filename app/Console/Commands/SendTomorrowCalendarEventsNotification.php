<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarEventsService;
use App\Services\Telegram\CalendarSummaryService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
        $tomorrow = now($timezone)->addDay()->startOfDay();

        $events = $calendarEventsService->getEventsForDay($tomorrow);
        $summary = $calendarSummaryService->build($tomorrow);

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

    protected function buildMessage(Carbon $date, $events, array $summary): string
    {
        $grouped = collect($events)->groupBy('type');

        $lines = [];

        $lines[] = '📅 <b>Сводка на завтра</b>';
        $lines[] = '<b>' . e(mb_convert_case($date->translatedFormat('l, j F Y'), MB_CASE_TITLE, 'UTF-8')) . '</b>';
        $lines[] = '';

        $shift = $summary['shift'] ?? null;

        if ($shift) {
            $lines[] = '👥 <b>Смена</b>';
            $lines[] = 'Работают: <b>' . ($shift['working'] ?? 0) . '/' . ($shift['total'] ?? 0) . '</b>';
            $lines[] = 'Не работают: <b>' . ($shift['not_working'] ?? 0) . '</b>';
            $lines[] = 'Статус: <b>' . e($shift['label'] ?? 'Без статуса') . '</b>';
            $lines[] = '';
        }

        $notWorking = collect(data_get($summary, 'workers.not_working', []));

        if ($notWorking->isNotEmpty()) {
            $lines[] = '🚫 <b>Кто не работает</b>';

            foreach ($notWorking as $user) {
                $reason = $user->not_working_reason ?? 'Не работает';
                $lines[] = '• ' . e($user->name) . ' — ' . e($reason);
            }

            $lines[] = '';
        }

        if ($events->isEmpty()) {
            $lines[] = '📌 <b>События</b>';
            $lines[] = 'На завтра событий нет.';

            return trim(implode("\n", $lines));
        }

        $lines[] = '📌 <b>События</b>';
        $lines[] = 'Всего событий: <b>' . $events->count() . '</b>';
        $lines[] = '';

        foreach ($this->eventTypeOrder() as $type) {
            $items = $grouped->get($type);

            if (! $items || $items->isEmpty()) {
                continue;
            }

            $lines[] = $this->typeIcon($type) . ' <b>' . e($this->typeLabel($type)) . '</b>';

            foreach ($items as $event) {
                $lines[] = $this->formatTelegramEventLine($event);
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    protected function formatTelegramEventLine(array $event): string
    {
        $lines = [];

        $lines[] = '• <b>' . e($event['title']) . '</b>';

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
            'day_off',
            'vacation',
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
            'peak' => '🔥',
            'day_off' => '🌿',
            'vacation' => '🏖',
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
            'peak' => 'Пики загрузки',
            'day_off' => 'Выходные',
            'vacation' => 'Отпуска',
            'strike' => 'Забастовки',
            default => 'Другое',
        };
    }
}
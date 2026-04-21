<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarEventsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTomorrowCalendarEventsNotification extends Command
{
    protected $signature = 'calendar:notify-tomorrow';
    protected $description = 'Send Telegram notification with all tomorrow calendar events';

    public function handle(CalendarEventsService $calendarEventsService): int
    {
        $timezone = config('app.timezone', 'Europe/Stockholm');
        $tomorrow = now($timezone)->addDay()->startOfDay();

        $events = $calendarEventsService->getEventsForDay($tomorrow);

        if ($events->isEmpty()) {
            $this->info('На завтра событий нет.');
            return self::SUCCESS;
        }

        $message = $this->buildMessage($tomorrow, $events);

        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id_calendar');
        $threadId = config('services.telegram.thread_id_calendar');

        if (! $botToken || ! $chatId) {
            $this->error('Не настроены services.telegram.bot_token или services.telegram.chat_id_calendar');
            return self::FAILURE;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($threadId) {
            $payload['message_thread_id'] = $threadId;
        }

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('Нет соединения с Telegram API: ' . $e->getMessage());

            Log::error('Calendar tomorrow notification connection failed', [
                'message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            Log::error('Calendar tomorrow notification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            $this->error('Не удалось отправить сообщение в Telegram.');
            $this->line('HTTP: ' . $response->status());
            $this->line('Response: ' . $response->body());

            return self::FAILURE;
        }

        $this->info('Уведомление отправлено.');
        return self::SUCCESS;
    }

    protected function buildMessage(Carbon $date, $events): string
    {
        $grouped = collect($events)->groupBy('type');

        $lines = [];
        $lines[] = '📅 <b>События на завтра</b>';
        $lines[] = '<b>' . e(mb_convert_case($date->translatedFormat('l, j F Y'), MB_CASE_TITLE, 'UTF-8')) . '</b>';
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
            $lines[] = '' . e($range) . '';
        }

        if (! empty($event['description'])) {
            $description = mb_strimwidth(trim($event['description']), 0, 180, '...');
            $lines[] = '  <blockquote>' . e($description) . '</blockquote>';
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
            'vacation',
            'workflow',
            'finance',
            'peak',
            'strike',
        ];
    }

    protected function typeIcon(string $type): string
    {
        return match ($type) {
            'workflow' => '🏢',
            'finance' => '💸',
            'holiday' => '🎉',
            'peak' => '🔥',
            'vacation' => '🌿',
            'strike' => '⚠️',
            default => '•',
        };
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'workflow' => 'Рабочие процессы',
            'finance' => 'Финансы',
            'holiday' => 'Праздники',
            'peak' => 'Пики загрузки',
            'vacation' => 'Выходные и отпуска',
            'strike' => 'Забастовки',
            default => 'Другое',
        };
    }
}
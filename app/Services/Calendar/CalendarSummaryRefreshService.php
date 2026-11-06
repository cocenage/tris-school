<?php

namespace App\Services\Calendar;

use App\Console\Commands\SendTomorrowCalendarEventsNotification;
use App\Models\TelegramOutboundOperation;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalendarSummaryRefreshService
{
    public function __construct(
        private CalendarEventsService $calendarEventsService,
        private CalendarSummaryService $calendarSummaryService,
        private TelegramBotService $telegramBotService,
        private TelegramOutboundMonitor $monitor,
    ) {}

    /** Update a previously delivered calendar summary without creating a new message. */
    public function refresh(CarbonInterface|string $date): bool
    {
        $date = Carbon::parse($date, 'Europe/Rome')->startOfDay();
        $dateKey = $date->toDateString();
        $operationKey = 'calendar:tomorrow:'.$dateKey;

        try {
            $lock = Cache::lock('telegram-calendar-summary:'.hash('sha256', $operationKey), 120);

            if (! $lock->get()) {
                return false;
            }
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        try {
            return $this->refreshLocked($date, $dateKey, $operationKey);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    private function refreshLocked(Carbon $date, string $dateKey, string $operationKey): bool
    {
        $chatId = config('services.telegram.chat_id_calendar');

        if (! filled($chatId)) {
            return false;
        }

        $operation = TelegramOutboundOperation::query()
            ->where('operation_key', $operationKey)
            ->where('notification_type', 'calendar')
            ->where('bot', 'work')
            ->where('chat_id', (string) $chatId)
            ->where('source_type', 'CalendarDate')
            ->where('source_id', $dateKey)
            ->whereIn('status', ['sent', 'edited', 'failed', 'uncertain'])
            ->whereNotNull('telegram_message_id')
            ->first();

        if ($operation === null) {
            return false;
        }

        $message = app(SendTomorrowCalendarEventsNotification::class)->buildForDate(
            $date,
            $this->calendarEventsService,
            $this->calendarSummaryService,
        );
        $contentHash = hash('sha256', $message);

        if (filled($operation->last_sent_content_hash)
            && hash_equals((string) $operation->last_sent_content_hash, $contentHash)) {
            return false;
        }

        $context = [
            'operation_key' => $operationKey,
            'notification_type' => 'calendar',
            'bot' => 'work',
            'chat_id' => (string) $chatId,
            'message_thread_id' => $operation->message_thread_id,
            'source_type' => 'CalendarDate',
            'source_id' => $dateKey,
            'accept_not_modified_edit' => true,
        ];

        $accepted = $this->monitor->withContext($context, fn (): bool => $this->telegramBotService->editMessage(
            chatId: (string) $chatId,
            messageId: (int) $operation->telegram_message_id,
            richMessage: [],
            fallbackHtml: $message,
            forceLegacy: true,
        ));

        if (! $accepted) {
            Log::warning('Calendar summary Telegram edit was not accepted.', ['date' => $dateKey]);
        }

        return $accepted;
    }

    private function logFailure(Throwable $exception): void
    {
        try {
            Log::warning('Calendar summary refresh failed.', ['error' => class_basename($exception)]);
        } catch (Throwable) {
            // Refresh failures are recovered by the scheduled reconciliation.
        }
    }
}

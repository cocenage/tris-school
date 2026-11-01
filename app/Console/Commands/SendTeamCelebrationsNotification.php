<?php

namespace App\Console\Commands;

use App\Services\Calendar\TeamCelebrationService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendTeamCelebrationsNotification extends Command
{
    protected $signature = 'calendar:notify-celebrations {--date=} {--dry-run}';

    protected $description = 'Send date-specific team celebration messages via Telegram';

    public function handle(
        TeamCelebrationService $celebrationService,
        TelegramBotService $telegramBotService,
        TelegramOutboundMonitor $monitor,
    ): int {
        $date = $this->requestedDate();

        if (! $date) {
            $this->error('Укажите корректную дату в формате YYYY-MM-DD.');

            return self::FAILURE;
        }

        $events = $celebrationService->eventsForDate($date);

        if ($this->option('dry-run')) {
            $this->line('📣 Поздравления на '.$date->format('d.m.Y').':');

            if ($events->isEmpty()) {
                $this->line('На эту дату событий нет.');

                return self::SUCCESS;
            }

            foreach ($events as $event) {
                $this->line((string) $event['text']);
                $this->line('Фото: '.(filled($event['photo_path'] ?? null) ? 'да' : 'нет'));
            }

            return self::SUCCESS;
        }

        if ($date->toDateString() !== CarbonImmutable::now('Europe/Rome')->toDateString()) {
            $this->error('Фактическая отправка разрешена только для сегодняшней даты Europe/Rome.');

            return self::FAILURE;
        }

        if ($events->isEmpty()) {
            $this->info('На сегодня поздравлений нет.');

            return self::SUCCESS;
        }

        $chatId = config('services.telegram.chat_id_celebrations');
        $threadId = config('services.telegram.thread_id_celebrations');

        if (! filled($chatId)) {
            Log::warning('Team celebration delivery skipped: dedicated Telegram route is missing.');
            $this->error('Не настроен отдельный маршрут services.telegram.chat_id_celebrations.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('telegram_outbound_operations')) {
            Log::warning('Team celebration delivery skipped: outbound journal is unavailable.');
            $this->error('Журнал исходящих операций недоступен; отправка остановлена.');

            return self::FAILURE;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($events as $event) {
            $photoPath = $event['photo_path'] ?? null;
            $usePhoto = is_string($photoPath) && is_file($photoPath) && is_readable($photoPath);
            $method = $usePhoto ? 'sendPhoto' : 'sendMessage';
            $context = [
                'operation_key' => $event['operation_key'],
                'notification_type' => 'team_celebration',
                'method' => $method,
                'bot' => 'work',
                'chat_id' => (string) $chatId,
                'message_thread_id' => filled($threadId) ? (string) $threadId : null,
                'source_type' => $event['source_type'],
                'source_id' => (string) $event['source_id'],
                'scheduled_for' => $date->setTime(9, 0),
            ];

            if (! $monitor->reserveExpected($context)) {
                $skipped++;

                continue;
            }

            try {
                $messageId = $monitor->withContext($context, fn () => $usePhoto
                    ? $telegramBotService->sendPhoto((string) $chatId, $photoPath, (string) $event['text'], filled($threadId) ? (string) $threadId : null)
                    : $telegramBotService->sendMessage((string) $chatId, (string) $event['text'], filled($threadId) ? (string) $threadId : null));
            } catch (Throwable $exception) {
                Log::error('Team celebration Telegram request failed.', [
                    'event_type' => $event['kind'],
                    'exception' => class_basename($exception),
                ]);
                $failed++;

                continue;
            }

            if (! is_numeric($messageId)) {
                Log::warning('Team celebration Telegram request has no confirmed message receipt.', [
                    'event_type' => $event['kind'],
                ]);
                $failed++;

                continue;
            }

            $sent++;
        }

        $this->info("Поздравления: отправлено {$sent}, уже учтено или ожидало сверки {$skipped}, без подтверждения {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function requestedDate(): ?CarbonImmutable
    {
        $value = $this->option('date');

        if (! filled($value)) {
            return CarbonImmutable::now('Europe/Rome')->startOfDay();
        }

        try {
            $date = CarbonImmutable::parse((string) $value, 'Europe/Rome')->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === (string) $value ? $date : null;
    }
}

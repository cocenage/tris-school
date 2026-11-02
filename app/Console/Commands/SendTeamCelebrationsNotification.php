<?php

namespace App\Console\Commands;

use App\Models\TelegramOutboundOperation;
use App\Services\Calendar\TeamCelebrationService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendTeamCelebrationsNotification extends Command
{
    protected $signature = 'calendar:notify-celebrations {--date=} {--dry-run} {--days=1}';

    protected $description = 'Send the daily calendar events digest to the celebrations topic';

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

        $days = $this->requestedDays();
        if ($days === null) {
            $this->error('Параметр --days может быть только 1 или 7.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($days !== 1 && ! $dryRun) {
            $this->error('Окно на семь дней доступно только для предварительного просмотра.');

            return self::FAILURE;
        }

        if (! $dryRun && $date->toDateString() !== CarbonImmutable::now('Europe/Rome')->toDateString()) {
            $this->error('Фактическая отправка разрешена только для сегодняшней даты Europe/Rome.');

            return self::FAILURE;
        }

        $previews = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $previewDate = $date->addDays($offset)->startOfDay();
            $sourceEvents = $celebrationService->eventsForDate($previewDate);
            $events = $this->withoutAlreadyDeliveredLegacyEvents($sourceEvents);
            $previews[] = [
                'date' => $previewDate,
                'source_event_count' => $sourceEvents->count(),
                'events' => $events,
                'digest' => $celebrationService->formatDigest($previewDate, $events),
            ];
        }

        if ($dryRun) {
            if ($days === 1) {
                $this->line('📣 Предпросмотр сводки на '.$date->format('d.m.Y').':');
            } else {
                $this->line('📣 Предпросмотр сводки с '.$date->format('d.m.Y').' по '.$date->addDays($days - 1)->format('d.m.Y').':');
            }

            foreach ($previews as $preview) {
                $digest = $preview['digest'];
                if ($days > 1) {
                    $this->line('— '.$preview['date']->format('d.m.Y').' —');
                }

                if ($digest === null) {
                    $this->line($preview['source_event_count'] > 0
                        ? 'Все события на эту дату уже учтены.'
                        : 'На эту дату событий нет.');

                    continue;
                }

                if ($digest['over_limit']) {
                    $this->warn('Предпросмотр превышает лимит Telegram (4096 символов); отправка не выполняется.');
                }

                $this->line($digest['text']);
                $this->line('Фото: '.(filled($digest['photo_path']) ? 'да' : 'нет'));
            }

            return self::SUCCESS;
        }

        $events = $previews[0]['events'];
        $digest = $previews[0]['digest'];
        if ($digest === null) {
            $this->info($previews[0]['source_event_count'] === 0
                ? 'На сегодня событий нет.'
                : 'Все события на сегодня уже учтены.');

            return self::SUCCESS;
        }

        if ($digest['over_limit']) {
            Log::warning('Calendar celebration digest exceeds the Telegram message limit.', [
                'date' => $date->toDateString(),
                'character_count' => $digest['character_count'],
            ]);
            $this->error('Сводка превышает лимит Telegram (4096 символов); она не отправлена.');

            return self::FAILURE;
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

        $photoPath = $digest['photo_path'];
        $usePhoto = is_string($photoPath) && is_file($photoPath) && is_readable($photoPath);
        $method = $usePhoto ? 'sendPhoto' : 'sendMessage';
        $route = implode('|', [(string) $chatId, (string) $threadId]);
        $context = [
            'operation_key' => 'calendar:celebrations:'.$date->toDateString().':'.substr(hash('sha256', $route), 0, 20),
            'notification_type' => 'calendar',
            'method' => $method,
            'bot' => 'work',
            'chat_id' => (string) $chatId,
            'message_thread_id' => filled($threadId) ? (string) $threadId : null,
            'source_type' => 'TeamCelebrationDigest',
            'source_id' => $date->toDateString(),
            'scheduled_for' => $date->setTime(9, 0),
        ];

        if (! $monitor->reserveExpected($context)) {
            $this->info('Сводка на эту дату уже отправлена или ожидает сверки.');

            return self::SUCCESS;
        }

        try {
            $messageId = $monitor->withContext($context, fn () => $usePhoto
                ? $telegramBotService->sendPhoto((string) $chatId, $photoPath, (string) $digest['text'], filled($threadId) ? (string) $threadId : null)
                : $telegramBotService->sendMessage((string) $chatId, (string) $digest['text'], filled($threadId) ? (string) $threadId : null));
        } catch (Throwable $exception) {
            Log::error('Calendar celebration digest Telegram request failed.', [
                'exception' => class_basename($exception),
            ]);
            $this->error('Сводка не получила подтверждение отправки.');

            return self::FAILURE;
        }

        if (! is_numeric($messageId)) {
            Log::warning('Calendar celebration digest has no confirmed Telegram receipt.');
            $this->error('Сводка не получила подтверждение отправки.');

            return self::FAILURE;
        }

        $this->info('Сводка событий отправлена в Telegram.');

        return self::SUCCESS;
    }

    private function withoutAlreadyDeliveredLegacyEvents(Collection $events): Collection
    {
        if ($events->isEmpty() || ! Schema::hasTable('telegram_outbound_operations')) {
            return $events;
        }

        $legacyKeys = $events->pluck('operation_key')->filter()->values();
        if ($legacyKeys->isEmpty()) {
            return $events;
        }

        $handledKeys = TelegramOutboundOperation::query()
            ->where('notification_type', 'team_celebration')
            ->whereIn('operation_key', $legacyKeys)
            ->whereIn('status', ['expected', 'pending', 'sent', 'edited', 'uncertain'])
            ->pluck('operation_key')
            ->all();

        return $events
            ->reject(fn (array $event): bool => in_array($event['operation_key'] ?? null, $handledKeys, true))
            ->values();
    }

    private function requestedDays(): ?int
    {
        $value = (string) $this->option('days');

        return in_array($value, ['1', '7'], true) ? (int) $value : null;
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

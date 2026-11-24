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

    protected $description = 'Send same-day birthdays and next-day celebration digests';

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

        $tomorrow = CarbonImmutable::now('Europe/Rome')->addDay()->startOfDay();
        if (! $dryRun && $date->toDateString() !== $tomorrow->toDateString()) {
            $this->error('Фактическая отправка разрешена только для сводки на завтра Europe/Rome.');

            return self::FAILURE;
        }

        $previews = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $previewDate = $date->addDays($offset)->startOfDay();
            $birthdayDate = $previewDate->subDay()->startOfDay();
            $birthdaySourceEvents = $celebrationService->eventsForDate($birthdayDate)
                ->where('kind', 'birthday')
                ->values();
            $birthdays = $this->withoutAlreadyDeliveredLegacyEvents($birthdaySourceEvents);
            $summarySourceEvents = $celebrationService->eventsForDate($previewDate)
                ->reject(fn (array $event): bool => ($event['kind'] ?? null) === 'birthday')
                ->values();
            $events = $this->withoutAlreadyDeliveredLegacyEvents($summarySourceEvents);
            $digest = $celebrationService->formatDigest($previewDate, $events);
            // The calendar topic receives an explicit daily status, even when
            // tomorrow has no events. Do not claim "no events" when there were
            // events already delivered through the legacy route.
            if ($digest === null && $summarySourceEvents->isEmpty()) {
                $digest = $this->emptyDayDigest($previewDate);
            }
            $previews[] = [
                'date' => $previewDate,
                'birthday_date' => $birthdayDate,
                'source_event_count' => $birthdaySourceEvents->count() + $summarySourceEvents->count(),
                'birthday_source_event_count' => $birthdaySourceEvents->count(),
                'summary_source_event_count' => $summarySourceEvents->count(),
                'events' => $events,
                'birthdays' => $birthdays,
                'digest' => $digest,
            ];
        }

        if ($dryRun) {
            if ($days === 1) {
                $this->line('📣 Предпросмотр поздравлений на '.$date->subDay()->format('d.m.Y').' и событий на '.$date->format('d.m.Y').':');
            } else {
                $this->line('📣 Предпросмотр поздравлений с '.$date->subDay()->format('d.m.Y').' по '.$date->addDays($days - 2)->format('d.m.Y')
                    .' и событий с '.$date->format('d.m.Y').' по '.$date->addDays($days - 1)->format('d.m.Y').':');
            }

            foreach ($previews as $preview) {
                $digest = $preview['digest'];
                if ($days > 1) {
                    $this->line('— Поздравления на '.$preview['birthday_date']->format('d.m.Y').', события на '.$preview['date']->format('d.m.Y').' —');
                }

                $this->line('🎂 Поздравления:');
                foreach ($preview['birthdays'] as $birthday) {
                    $this->line($birthday['text']);
                    $this->line('Фото: '.(filled($birthday['photo_path']) ? 'да' : 'нет'));
                }
                if ($preview['birthdays']->isEmpty()) {
                    $this->line($preview['birthday_source_event_count'] > 0
                        ? 'Поздравления на эту дату уже учтены.'
                        : 'Дней рождения нет.');
                }

                $this->line('📅 События:');
                if ($digest !== null) {
                    if ($digest['over_limit']) {
                        $this->warn('Предпросмотр превышает лимит Telegram (4096 символов); отправка не выполняется.');
                    }

                    $this->line($digest['text']);
                    $this->line('Фото: '.(filled($digest['photo_path']) ? 'да' : 'нет'));
                } else {
                    $this->line($preview['summary_source_event_count'] > 0
                        ? 'События на эту дату уже учтены.'
                        : 'Событий на эту дату нет.');
                }
            }

            return self::SUCCESS;
        }

        $birthdays = $previews[0]['birthdays'];
        $digest = $previews[0]['digest'];
        if ($birthdays->isEmpty() && $digest === null) {
            $this->info($previews[0]['source_event_count'] === 0
                ? 'Нет событий для отправки.'
                : 'Все события на выбранные даты уже учтены.');

            return self::SUCCESS;
        }

        $digestOverLimit = $digest !== null && $digest['over_limit'];
        if ($digestOverLimit) {
            Log::warning('Calendar celebration digest exceeds the Telegram message limit.', [
                'date' => $date->toDateString(),
                'character_count' => $digest['character_count'],
            ]);
        }

        $chatId = config('services.telegram.chat_id_celebrations');
        $threadId = config('services.telegram.thread_id_celebrations');
        $threadIdValue = is_scalar($threadId) ? (string) $threadId : '';
        $threadIdDigits = ctype_digit($threadIdValue) ? (ltrim($threadIdValue, '0') ?: '0') : '';
        $validatedThreadId = $threadIdDigits !== ''
            ? filter_var($threadIdDigits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (! filled($chatId) || $validatedThreadId === false) {
            Log::warning('Team celebration delivery skipped: dedicated Telegram forum topic is missing.');
            $this->error('Не настроен отдельный форумный топик services.telegram.chat_id_celebrations/thread_id_celebrations.');

            return self::FAILURE;
        }
        $threadId = (string) $validatedThreadId;

        if (! Schema::hasTable('telegram_outbound_operations')) {
            Log::warning('Team celebration delivery skipped: outbound journal is unavailable.');
            $this->error('Журнал исходящих операций недоступен; отправка остановлена.');

            return self::FAILURE;
        }

        $legacyDigestIncludesBirthday = $this->legacyDigestMayContainBirthday(
            $previews[0]['birthday_date'],
            (string) $chatId,
            $threadId,
        );
        if ($legacyDigestIncludesBirthday === null) {
            $this->error('Не удалось проверить прежнюю рассылку; поздравления не отправлены.');

            return self::FAILURE;
        }

        if ($legacyDigestIncludesBirthday) {
            $birthdays = collect();
        }

        $birthdaySent = false;
        $birthdayDeliveryFailed = false;
        if ($birthdays->isNotEmpty()) {
            $birthdayCard = $birthdays->first()['photo_path'] ?? null;
            if (! is_string($birthdayCard) || ! is_file($birthdayCard) || ! is_readable($birthdayCard)) {
                $birthdayDeliveryFailed = true;
                $birthdays = collect();
                Log::warning('Calendar birthday messages skipped: monthly card is unavailable.', [
                    'month' => $previews[0]['birthday_date']->format('m'),
                ]);
                $this->error('Нет читаемой открытки для месяца '.$previews[0]['birthday_date']->format('m').'; поздравления не отправлены.');
            }
        }

        foreach ($birthdays as $birthday) {
            $result = $this->sendBirthdayEvent($birthday, $previews[0]['birthday_date'], (string) $chatId, $threadId, $telegramBotService, $monitor);

            if ($result === false) {
                $birthdayDeliveryFailed = true;

                continue;
            }

            $birthdaySent = $birthdaySent || $result === true;
        }

        if ($digest === null) {
            if ($birthdayDeliveryFailed) {
                return self::FAILURE;
            }

            $this->info($birthdaySent
                ? 'Поздравление отправлено в Telegram.'
                : ($legacyDigestIncludesBirthday
                    ? 'Поздравление уже было отправлено в предыдущей сводке.'
                    : 'Поздравление уже отправлено или ожидает сверки.'));

            return self::SUCCESS;
        }

        if ($digestOverLimit) {
            $this->error('Сводка превышает лимит Telegram (4096 символов); она не отправлена.');

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
            'source_type' => 'TeamCelebrationNonBirthdayDigest',
            'source_id' => $date->toDateString(),
            'scheduled_for' => $date->setTime(6, 5),
        ];

        if (! $monitor->reserveExpected($context)) {
            if ($this->operationIsAlreadyHandled((string) $context['operation_key']) !== true) {
                $this->error('Сводку не удалось зарезервировать; отправка остановлена.');

                return self::FAILURE;
            }

            $this->info('Сводка на эту дату уже отправлена или ожидает сверки.');

            return $birthdayDeliveryFailed ? self::FAILURE : self::SUCCESS;
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

        if ($birthdayDeliveryFailed) {
            $this->error('Сводка отправлена, но поздравления не отправлены.');

            return self::FAILURE;
        }

        $this->info('Сводка событий отправлена в Telegram.');

        return self::SUCCESS;
    }

    /** @return array{text: string, photo_path: null, over_limit: false, character_count: int} */
    private function emptyDayDigest(CarbonImmutable $date): array
    {
        $text = '📅 <b>События на '.$date->format('d.m.Y')."</b>\n\nℹ️ На завтра событий нет.";

        return [
            'text' => $text,
            'photo_path' => null,
            'over_limit' => false,
            'character_count' => mb_strlen(strip_tags($text)),
        ];
    }

    private function sendBirthdayEvent(
        array $event,
        CarbonImmutable $date,
        string $chatId,
        ?string $threadId,
        TelegramBotService $telegramBotService,
        TelegramOutboundMonitor $monitor,
    ): ?bool {
        $photoPath = $event['photo_path'] ?? null;
        if (! is_string($photoPath) || ! is_file($photoPath) || ! is_readable($photoPath)) {
            Log::warning('Calendar birthday delivery skipped: monthly card is unavailable.');
            $this->error('Открытка для поздравления недоступна; поздравление не отправлено.');

            return false;
        }

        $context = [
            'operation_key' => (string) $event['operation_key'],
            'notification_type' => 'team_celebration',
            'method' => 'sendPhoto',
            'bot' => 'work',
            'chat_id' => $chatId,
            'message_thread_id' => filled($threadId) ? (string) $threadId : null,
            'source_type' => (string) $event['source_type'],
            'source_id' => (string) $event['source_id'],
            'scheduled_for' => $date->setTime(6, 5),
        ];

        if (! $this->reserveBirthdayOperation($context, $monitor)) {
            if ($this->operationIsAlreadyHandled((string) $context['operation_key']) === true) {
                return null;
            }

            Log::warning('Calendar birthday delivery could not be reserved.');
            $this->error('Поздравление не удалось зарезервировать для отправки.');

            return false;
        }

        try {
            $messageId = $monitor->withContext($context, fn () => $telegramBotService->sendPhoto(
                $chatId,
                $photoPath,
                (string) $event['text'],
                filled($threadId) ? (string) $threadId : null,
            ));
        } catch (Throwable $exception) {
            Log::error('Calendar birthday Telegram request failed.', [
                'exception' => class_basename($exception),
            ]);
            $this->error('Поздравление не получило подтверждение отправки.');

            return false;
        }

        if (! is_numeric($messageId)) {
            Log::warning('Calendar birthday Telegram message has no confirmed receipt.');
            $this->error('Поздравление не получило подтверждение отправки.');

            return false;
        }

        return true;
    }

    private function reserveBirthdayOperation(array $context, TelegramOutboundMonitor $monitor): bool
    {
        if ($monitor->reserveExpected($context)) {
            return true;
        }

        try {
            $reopened = TelegramOutboundOperation::query()
                ->where('operation_key', $context['operation_key'])
                ->where('notification_type', 'team_celebration')
                ->where('status', 'failed')
                ->update([
                    'status' => 'expected',
                    'error_code' => null,
                    'error_reason' => null,
                    'finished_at' => null,
                    'telegram_message_id' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('Failed birthday operation could not be reopened.', [
                'exception' => class_basename($exception),
            ]);

            return false;
        }

        return $reopened === 1 && $monitor->reserveExpected($context);
    }

    private function legacyDigestMayContainBirthday(CarbonImmutable $date, string $chatId, ?string $threadId): ?bool
    {
        $route = implode('|', [$chatId, (string) $threadId]);
        $operationKey = 'calendar:celebrations:'.$date->toDateString().':'.substr(hash('sha256', $route), 0, 20);

        try {
            return TelegramOutboundOperation::query()
                ->where('operation_key', $operationKey)
                ->where('notification_type', 'calendar')
                ->where('source_type', 'TeamCelebrationDigest')
                ->whereIn('status', ['expected', 'pending', 'sent', 'edited', 'uncertain'])
                ->exists();
        } catch (Throwable $exception) {
            Log::warning('Legacy birthday digest check failed.', [
                'exception' => class_basename($exception),
            ]);

            return null;
        }
    }

    private function operationIsAlreadyHandled(string $operationKey): ?bool
    {
        try {
            $status = TelegramOutboundOperation::query()
                ->where('operation_key', $operationKey)
                ->value('status');
        } catch (Throwable $exception) {
            Log::warning('Telegram outbound status check failed.', [
                'exception' => class_basename($exception),
            ]);

            return null;
        }

        return in_array($status, ['expected', 'pending', 'sent', 'edited', 'uncertain'], true);
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
            return CarbonImmutable::now('Europe/Rome')->addDay()->startOfDay();
        }

        try {
            $date = CarbonImmutable::parse((string) $value, 'Europe/Rome')->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === (string) $value ? $date : null;
    }
}

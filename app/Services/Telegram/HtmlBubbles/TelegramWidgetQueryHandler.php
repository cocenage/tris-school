<?php

namespace App\Services\Telegram\HtmlBubbles;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class TelegramWidgetQueryHandler
{
    public function __construct(
        private TelegramHtmlBubblesClient $client,
        private TelegramHtmlBubblesTargetGuard $targetGuard,
    ) {}

    public function handle(array $query): JsonResponse
    {
        if (! (bool) config('services.telegram.htmlbubbles.enabled', false)) {
            return response()->json(['ok' => true, 'skipped' => 'htmlbubbles_disabled']);
        }

        $chatId = data_get($query, 'chat.id');
        $messageId = filter_var($query['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $queryId = $query['id'] ?? null;
        $telegramUserId = data_get($query, 'from.id');
        $action = $query['data'] ?? null;

        if (! is_numeric($chatId)
            || $messageId === false
            || ! is_scalar($queryId)
            || trim((string) $queryId) === ''
            || ! is_numeric($telegramUserId)
            || ! is_string($action)) {
            return response()->json(['ok' => true, 'skipped' => 'invalid_widget_query']);
        }

        $chatId = (string) $chatId;
        $queryId = (string) $queryId;

        if (! $this->targetGuard->isConfiguredTestChat($chatId)) {
            return response()->json(['ok' => true, 'skipped' => 'not_test_destination']);
        }

        $messageKey = $this->targetGuard->messageCacheKey($chatId, (int) $messageId);
        $messageState = Cache::get($messageKey);

        if (! is_array($messageState)) {
            return $this->answer($queryId, 'Эта карточка не зарегистрирована как текущий тестовый виджет.');
        }

        $queryCacheKey = 'telegram:htmlbubbles:query:'.hash('sha256', $queryId);

        if (Cache::has($queryCacheKey)) {
            return response()->json(['ok' => true, 'handled' => true, 'duplicate' => true]);
        }

        $user = User::query()
            ->where('telegram_id', (string) $telegramUserId)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->first();

        if ($action === 'access:check') {
            $result = $user
                ? 'Список районных дежурств не подключён. Полномочия для реального контроля не подтверждены.'
                : 'Профиль Академии не найден или не подтверждён. Доступ к контролям не предоставлен.';

            return $this->answerAndRemember($queryId, $queryCacheKey, $result);
        }

        if ($action !== 'demo:complete') {
            return $this->answerAndRemember($queryId, $queryCacheKey, 'Неизвестное тестовое действие.');
        }

        if (! $user || $user->role !== 'admin') {
            return $this->answerAndRemember($queryId, $queryCacheKey, 'Изменять тестовое состояние может только администратор Академии.');
        }

        $result = $this->completeDemoState($chatId, (int) $messageId, $messageKey);

        return $this->answerAndRemember($queryId, $queryCacheKey, $result);
    }

    private function completeDemoState(string $chatId, int $messageId, string $messageKey): string
    {
        $lock = Cache::lock($messageKey.':update-lock', 10);

        if (! $lock->get()) {
            return 'Тестовый статус уже обновляется. Повторите попытку позже.';
        }

        try {
            $state = Cache::get($messageKey);

            if (! is_array($state)) {
                return 'Эта карточка больше не активна для тестирования.';
            }

            if (($state['status'] ?? null) === 'complete') {
                return 'Тестовый статус уже обновлён.';
            }

            $chatData = json_encode(['version' => 1, 'status' => 'complete'], JSON_THROW_ON_ERROR);

            try {
                $this->client->editMessageWidgetData(
                    (string) config('services.telegram.bot_token', ''),
                    $chatId,
                    $messageId,
                    $chatData,
                );
            } catch (Throwable $error) {
                Log::warning('Telegram HTMLBubble test-state edit failed', [
                    'exception' => class_basename($error),
                ]);

                return 'Не удалось обновить тестовый статус. Реальные данные не изменены.';
            }

            Cache::put($messageKey, ['status' => 'complete'], now()->addHours(24));

            return 'Тестовый статус обновлён в исходной карточке.';
        } catch (JsonException $error) {
            Log::warning('Telegram HTMLBubble test-state encoding failed', [
                'exception' => class_basename($error),
            ]);

            return 'Не удалось сформировать тестовый статус.';
        } finally {
            $lock->release();
        }
    }

    private function answerAndRemember(string $queryId, string $cacheKey, string $result): JsonResponse
    {
        $response = $this->answer($queryId, $result);
        Cache::put($cacheKey, true, now()->addHours(24));

        return $response;
    }

    private function answer(string $queryId, string $result): JsonResponse
    {
        try {
            $this->client->answerWidgetQuery(
                (string) config('services.telegram.bot_token', ''),
                $queryId,
                $result,
            );
        } catch (Throwable $error) {
            Log::warning('Telegram HTMLBubble query answer failed', [
                'exception' => class_basename($error),
            ]);
        }

        return response()->json(['ok' => true, 'handled' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramAssistantMessage;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\User;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramOperationalEventObserver;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramRichMessageBuilder;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use App\Services\Telegram\TelegramScheduledMessageDeliveryService;
use App\Services\Telegram\TelegramUpdateIngestService;
use App\Services\Telegram\TelegramWorkActionFreshness;
use App\Services\TelegramUserNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWorkWebhookController extends Controller
{
    private ?int $callbackStartedAt = null;

    public function __invoke(
        Request $request,
        string $secret,
        TelegramUpdateIngestService $ingestService,
        TelegramAssistantService $assistantService,
        TelegramScheduledControlResponseService $responses,
        TelegramDistrictRouteRegistry $districts,
    ) {
        if ($secret !== config('services.telegram.work_webhook_secret')) {
            abort(403);
        }

        return $this->processUpdate(
            $request->all(),
            $ingestService,
            $assistantService,
            $responses,
            $districts,
        );
    }

    public function processUpdate(
        array $update,
        TelegramUpdateIngestService $ingestService,
        TelegramAssistantService $assistantService,
        TelegramScheduledControlResponseService $responses,
        TelegramDistrictRouteRegistry $districts,
        bool $dispatchAssistantAfterResponse = true,
    ) {
        $actionFreshness = app(TelegramWorkActionFreshness::class);
        $updateType = match (true) {
            isset($update['callback_query']) => 'callback_query',
            isset($update['message']) => 'message',
            isset($update['edited_message']) => 'edited_message',
            isset($update['channel_post']) => 'channel_post',
            default => 'unknown',
        };

        Log::debug('Telegram work webhook received', [
            'update_id' => is_numeric($update['update_id'] ?? null) ? (int) $update['update_id'] : null,
            'update_type' => $updateType,
            'has_callback_query' => $updateType === 'callback_query',
        ]);

        if (isset($update['callback_query'])) {
            if ($actionFreshness->callbackRequiresReconciliation()) {
                $actionFreshness->recordManualReconciliation($update, 'callback_backlog_guard');

                return response()->json(['ok' => true, 'skipped' => 'manual_reconciliation_required']);
            }

            return $this->handleCallbackQuery($update['callback_query']);
        }

        $message = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? null;

        if (! $message) {
            return response()->json(['ok' => true, 'skipped' => 'no_message']);
        }

        if (($message['chat']['type'] ?? null) === 'private') {
            try {
                if (! $actionFreshness->messageIsCurrent($message)) {
                    $ingestService->ingest($update);
                    $actionFreshness->recordManualReconciliation($update, 'stale_private_message');

                    return response()->json(['ok' => true, 'skipped' => 'manual_reconciliation_required']);
                }

                $this->sendPrivateFallbackMessageStrict($message);

                return response()->json([
                    'ok' => true,
                    'skipped' => 'private_message',
                ]);
            } catch (\Throwable $e) {
                Log::error('Telegram private message fallback failed', [
                    'exception' => class_basename($e),
                ]);

                return response()->json(['ok' => false], 500);
            }
        }

        $chatId = (string) data_get($message, 'chat.id');

        $allowedChatIds = config('services.telegram.work_allowed_chat_ids', []);
        $isWorkAllowed = in_array($chatId, $allowedChatIds, true);
        $isOperationalChat = $districts->isOperationalChatId($chatId)
            && in_array(data_get($message, 'chat.type'), ['group', 'supergroup', 'channel'], true);
        $isControlDestination = ! $isWorkAllowed && $responses->isSupportedControlDestination($message);

        if (! $isWorkAllowed && ! $isOperationalChat && ! $isControlDestination) {
            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
                'chat_id' => $chatId,
            ]);
        }

        try {
            $currentForActions = $actionFreshness->messageIsCurrent($message);
            $activated = $isWorkAllowed && $assistantService->isActivated($message);
            $savedMessage = $ingestService->ingest($update, $currentForActions && $activated && $this->isInstructionCommand($message));

            if ($savedMessage && ! $currentForActions && $activated) {
                $actionFreshness->recordManualReconciliation($update, 'stale_assistant_message');
            }

            if ($savedMessage) {
                if ($isControlDestination || $isWorkAllowed) {
                    $controlResponse = $responses->capture($savedMessage, $message);
                    if ($controlResponse?->delivery?->bot_type === 'work') {
                        $this->syncWorkControlMessage($controlResponse->delivery);
                    }
                }
            }

            if ($savedMessage && ($isOperationalChat || $isWorkAllowed) && config('services.telegram.operational_observer_enabled', false)
                && ($savedMessage->raw === null
                    || ! app(TelegramOperationalEventObserver::class)->hasCompletedCurrentRevision($savedMessage))) {
                ProcessTelegramOperationalMessage::dispatch($savedMessage->id);
            }

            if ($savedMessage && $isWorkAllowed && $activated && $currentForActions) {
                try {
                    if ($dispatchAssistantAfterResponse) {
                        ProcessTelegramAssistantMessage::dispatchAfterResponse($savedMessage->id);
                    } else {
                        ProcessTelegramAssistantMessage::dispatch($savedMessage->id);
                    }
                } catch (\Throwable $e) {
                    Log::error('Telegram assistant processing could not be scheduled after ingest', [
                        'message_id' => $savedMessage->id,
                        'exception' => class_basename($e),
                    ]);

                    if (! $dispatchAssistantAfterResponse) {
                        throw $e;
                    }
                }
            }

            return response()->json([
                'ok' => true,
                'message_id' => $savedMessage?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram work webhook failed', [
                'exception' => class_basename($e),
            ]);

            return response()->json(['ok' => false], 500);
        }
    }

    private function isInstructionCommand(array $message): bool
    {
        return preg_match(
            '/^\/(?:help|assistant)(?:@[^\s]+)?\b/ui',
            trim((string) ($message['text'] ?? '')),
        ) === 1;
    }

    private function handleCallbackQuery(array $callbackQuery)
    {
        $data = is_string($callbackQuery['data'] ?? null) ? $callbackQuery['data'] : '';
        $this->callbackStartedAt = hrtime(true);

        $parts = explode(':', $data, 3);
        Log::info('Telegram callback received', [
            'callback_received' => true,
            'callback_type' => $parts[0] ?? 'unknown',
            'callback_action' => $parts[1] ?? 'unknown',
        ]);

        try {
            if (str_starts_with($data, 'access:')) {
                return $this->handleAccessCallback($callbackQuery);
            }

            if (str_starts_with($data, 'dayoffday:')) {
                return $this->handleDayOffDayCallback($callbackQuery);
            }

            if (str_starts_with($data, 'sc:')) {
                return $this->handleScheduledControlCallback($callbackQuery);
            }
        } catch (\Throwable $e) {
            $this->answerCallback($callbackQuery, 'Не удалось обработать действие. Попробуйте ещё раз.');
            Log::error('Telegram callback handler failed', [
                'callback_received' => true,
                'callback_type' => $parts[0] ?? 'unknown',
                'callback_action' => $parts[1] ?? 'unknown',
                'callback_target_id' => is_numeric($parts[2] ?? null) ? (int) $parts[2] : null,
                'telegram_user_found' => null,
                'application_found' => null,
                'authorization_passed' => null,
                'handler_result' => 'error',
                'exception' => class_basename($e),
                'duration_ms' => round((hrtime(true) - $this->callbackStartedAt) / 1_000_000, 2),
            ]);

            return response()->json(['ok' => true, 'handled' => false]);
        } finally {
            $this->callbackStartedAt = null;
        }

        $this->logCallbackEvent($data, null, null, null, 'unknown_callback');
        $this->answerCallback($callbackQuery, 'Это действие больше недоступно.');

        return response()->json([
            'ok' => true,
            'skipped' => 'unknown_callback',
        ]);
    }

    private function handleScheduledControlCallback(array $callbackQuery)
    {
        $data = (string) ($callbackQuery['data'] ?? '');
        if (preg_match('/^sc:(\d+):(?:lambrate|lodi|certosa|navigli|como):(yes|no)$/', $data, $matches) !== 1
            || ! TelegramScheduledMessageDelivery::query()->whereKey((int) $matches[1])->where('bot_type', 'work')->exists()) {
            $this->answerCallback($callbackQuery, 'Эта кнопка больше не действует.');

            return response()->json(['ok' => true, 'skipped' => 'unknown_control_callback']);
        }

        $result = app(TelegramScheduledControlResponseService::class)->captureCallback($callbackQuery);
        $delivery = $result['delivery'];
        if ($delivery === null || $result['response'] === null) {
            $this->answerCallback($callbackQuery, 'Эта кнопка больше не действует.');

            return response()->json(['ok' => true, 'callback_status' => $result['status']]);
        }

        if ($delivery->control_type === ScheduledControlTypes::CLOSING_CLEANINGS
            && app(TelegramScheduledControlResponseService::class)->allDistrictsConfirmed($delivery)
            && ! app(TelegramScheduledMessageDeliveryService::class)->queueClosingPayments($delivery)) {
            Log::warning('Scheduled control payment follow-up could not be queued.', ['delivery_id' => $delivery->id]);
        }

        $synced = $this->syncWorkControlMessage($delivery);
        $this->answerCallback($callbackQuery, $synced ? 'Ответ сохранён.' : 'Ответ сохранён; сообщение пока не обновилось.');

        return response()->json(['ok' => true, 'callback_status' => $result['status']]);
    }

    private function syncWorkControlMessage(TelegramScheduledMessageDelivery $delivery): bool
    {
        if ($delivery->bot_type !== 'work' || $delivery->status !== 'sent' || ! $delivery->telegram_message_id) {
            return false;
        }

        try {
            $edited = Cache::lock('telegram_work_control_sync:'.$delivery->id, 10)->block(3, function () use ($delivery): bool {
                $text = app(TelegramScheduledControlSummaryFormatter::class)->formatInteractive($delivery);

                return app(TelegramBotService::class)->editMessage(
                    (string) $delivery->chat_id, (int) $delivery->telegram_message_id, [], $text,
                    ScheduledControlTypes::districtKeyboard($delivery->id), true,
                );
            });
        } catch (\Throwable $exception) {
            Log::warning('Work control message sync failed.', ['delivery_id' => $delivery->id, 'exception' => class_basename($exception)]);

            return false;
        }

        if (! $edited) {
            Log::warning('Work control message edit was rejected.', ['delivery_id' => $delivery->id]);
        }

        return $edited;
    }

    private function handleAccessCallback(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';
        $parts = explode(':', $data);

        if (count($parts) !== 3) {
            $this->logCallbackEvent($data, null, false, false, 'bad_callback_data');
            $this->answerCallback($callbackQuery, 'Некорректная кнопка.');

            return response()->json(['ok' => true, 'skipped' => 'bad_callback_data']);
        }

        [, $action, $userId] = $parts;

        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $allowedChatIds = config('services.telegram.work_allowed_chat_ids', []);

        if (! empty($allowedChatIds) && ! in_array($chatId, $allowedChatIds, true)) {
            $this->logCallbackEvent($data, null, null, false, 'chat_not_allowed');
            $this->answerCallback($callbackQuery, 'Нет доступа к этому чату');

            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
                'chat_id' => $chatId,
            ]);
        }

        $user = User::find($userId);

        if (! $user) {
            $this->logCallbackEvent($data, false, false, false, 'user_not_found');
            $this->answerCallback($callbackQuery, 'Пользователь не найден');

            return response()->json(['ok' => true, 'skipped' => 'user_not_found']);
        }

        $fromTelegramId = data_get($callbackQuery, 'from.id');

        $moderator = $fromTelegramId
            ? User::where('telegram_id', (string) $fromTelegramId)->first()
            : null;

        if (! $this->canReviewRequests($moderator)) {
            $this->logCallbackEvent($data, $moderator !== null, true, false, 'authorization_denied');
            $this->answerCallback($callbackQuery, 'Недостаточно прав');

            return response()->json(['ok' => true, 'skipped' => 'reviewer_not_allowed']);
        }

        if ($user->status !== 'pending') {
            $this->logCallbackEvent($data, true, true, true, 'access_already_reviewed');
            $this->answerCallback($callbackQuery, 'По пользователю уже принято решение');
            $this->syncExistingAccessMessage($callbackQuery, $user);

            return response()->json(['ok' => true, 'skipped' => 'access_already_reviewed']);
        }

        $moderatorText = $this->telegramUserText($callbackQuery['from'] ?? []);

        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->logCallbackEvent($data, true, true, true, 'unknown_action');
            $this->answerCallback($callbackQuery, 'Неизвестное действие');

            return response()->json(['ok' => true, 'skipped' => 'unknown_action']);
        }

        $this->answerCallback(
            $callbackQuery,
            $action === 'approve' ? 'Обрабатываем одобрение' : 'Обрабатываем отклонение',
        );

        $decisionApplied = false;

        DB::transaction(function () use (&$user, $action, $moderator, &$decisionApplied): void {
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedUser || $lockedUser->status !== 'pending') {
                return;
            }

            // Bypass the UserObserver here. It sends Telegram synchronously;
            // a slow/failing API call must never turn a committed approval
            // into a webhook 500/retry.
            $lockedUser->forceFill([
                'status' => $action === 'approve' ? 'approved' : 'rejected',
                'approved_at' => $action === 'approve' ? now() : $lockedUser->approved_at,
                'approved_by' => $moderator?->id,
            ])->saveQuietly();

            $user = $lockedUser;
            $decisionApplied = true;
        });

        if (! $decisionApplied) {
            $this->logCallbackEvent($data, true, true, true, 'access_already_reviewed');
            $this->answerCallback($callbackQuery, 'Решение уже принято');
            $this->syncExistingAccessMessage($callbackQuery, $user->fresh());

            return response()->json(['ok' => true, 'skipped' => 'access_already_reviewed']);
        }

        if ($action === 'approve') {

            try {
                app(TelegramUserNotificationService::class)->accessApproved($user);
            } catch (\Throwable $e) {
                Log::warning('Telegram access approval notification failed after decision', [
                    'callback_received' => true,
                    'callback_type' => 'access',
                    'callback_action' => 'approve',
                    'handler_result' => 'decision_saved_notification_failed',
                    'exception' => class_basename($e),
                ]);
            }

            $statusText = '✅ <b>Доступ одобрен</b>';
        } else {
            $statusText = '❌ <b>Доступ отклонён</b>';
        }

        try {
            $handlerResult = $this->editAccessMessage($callbackQuery, $user, $statusText, $moderatorText)
                ? 'handled'
                : 'decision_saved_edit_failed';
        } catch (\Throwable $e) {
            Log::warning('Telegram access callback message edit failed after decision', [
                'callback_received' => true,
                'callback_type' => 'access',
                'callback_action' => $action,
                'handler_result' => 'decision_saved_edit_failed',
                'exception' => class_basename($e),
            ]);
            $handlerResult = 'decision_saved_edit_failed';
        }

        $this->logCallbackEvent($data, $moderator !== null, true, true, $handlerResult);

        return response()->json(['ok' => true]);
    }

    private function syncExistingAccessMessage(array $callbackQuery, User $user): void
    {
        $reviewer = $user->approved_by ? User::find($user->approved_by) : null;
        $status = $user->status === 'approved' ? '✅ <b>Доступ одобрен</b>' : '❌ <b>Доступ отклонён</b>';

        try {
            if (! $this->editAccessMessage($callbackQuery, $user, $status, $reviewer?->name ?: 'не указан')) {
                Log::warning('Telegram access message reconciliation was rejected.', ['user_id' => $user->id]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram access message reconciliation failed.', ['user_id' => $user->id, 'exception' => class_basename($exception)]);
        }
    }

    private function handleDayOffDayCallback(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';
        $parts = explode(':', $data);

        if (count($parts) !== 3) {
            $this->logCallbackEvent($data, null, false, false, 'bad_dayoffday_callback');
            $this->answerCallback($callbackQuery, 'Некорректная кнопка заявки.');

            return response()->json(['ok' => true, 'skipped' => 'bad_dayoffday_callback']);
        }

        [, $action, $dayId] = $parts;

        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $requestChatId = (string) config('services.telegram.chat_id_formweekend', '');
        $requestThreadId = config('services.telegram.thread_id_formweekend');
        $callbackThreadId = data_get($callbackQuery, 'message.message_thread_id');

        if (blank($requestChatId)
            || $chatId !== $requestChatId
            || (filled($requestThreadId) && filled($callbackThreadId) && (string) $callbackThreadId !== (string) $requestThreadId)) {
            $this->logCallbackEvent($data, null, null, false, 'dayoffday_wrong_destination');
            $this->answerCallback($callbackQuery, 'Кнопка не из чата заявок');

            return response()->json(['ok' => true, 'skipped' => 'dayoffday_wrong_destination']);
        }

        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->logCallbackEvent($data, null, null, null, 'unknown_dayoffday_action');
            $this->answerCallback($callbackQuery, 'Неизвестное действие');

            return response()->json(['ok' => true, 'skipped' => 'unknown_dayoffday_action']);
        }

        $day = DayOffRequestDay::with([
            'request.user',
            'request.days',
        ])->find($dayId);

        if (! $day) {
            $this->logCallbackEvent($data, false, false, false, 'dayoffday_not_found');
            $this->answerCallback($callbackQuery, 'Дата не найдена');

            return response()->json(['ok' => true, 'skipped' => 'dayoffday_not_found']);
        }

        $dayOffRequest = $day->request;

        if (! $dayOffRequest) {
            $this->logCallbackEvent($data, false, false, false, 'dayoff_request_not_found');
            $this->answerCallback($callbackQuery, 'Заявка не найдена');

            return response()->json(['ok' => true, 'skipped' => 'dayoff_request_not_found']);
        }

        $fromTelegramId = data_get($callbackQuery, 'from.id');

        $reviewer = $fromTelegramId
            ? User::where('telegram_id', (string) $fromTelegramId)->first()
            : null;

        if (! $this->canReviewRequests($reviewer)) {
            $this->logCallbackEvent($data, $reviewer !== null, true, false, 'authorization_denied');
            $this->answerCallback($callbackQuery, 'Недостаточно прав');

            return response()->json(['ok' => true, 'skipped' => 'reviewer_not_allowed']);
        }

        if ($day->status !== 'pending') {
            $this->logCallbackEvent($data, true, true, true, 'dayoffday_already_reviewed');
            $this->answerCallback($callbackQuery, $day->status === 'approved' ? 'Дата уже одобрена' : 'Дата уже отклонена');

            $dayOffRequest->load(['user', 'days.reviewer']);
            $this->syncDayOffRequestMessage($callbackQuery, $dayOffRequest, $action);

            return response()->json(['ok' => true, 'skipped' => 'dayoffday_already_reviewed']);
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';

        $decisionApplied = false;

        DB::transaction(function () use (&$day, &$dayOffRequest, $status, $reviewer, &$decisionApplied): void {
            $lockedDay = DayOffRequestDay::query()->lockForUpdate()->find($day->id);

            if (! $lockedDay || $lockedDay->status !== 'pending') {
                return;
            }

            $lockedRequest = DayOffRequest::query()->lockForUpdate()->find($lockedDay->day_off_request_id);

            if (! $lockedRequest) {
                return;
            }

            $reviewedAt = now('UTC');

            $lockedDay->forceFill([
                'status' => $status,
                'reviewed_at' => $reviewedAt,
                'reviewed_by' => $reviewer->id,
            ])->save();

            $lockedRequest->forceFill([
                'reviewed_at' => $reviewedAt,
                'reviewed_by' => $reviewer->id,
            ])->save();

            $lockedRequest->syncStatusAndNotify();
            $day = $lockedDay;
            $dayOffRequest = $lockedRequest;
            $decisionApplied = true;
        });

        if (! $decisionApplied) {
            $this->logCallbackEvent($data, true, true, true, 'dayoffday_already_reviewed');
            $this->answerCallback($callbackQuery, 'Решение уже принято');

            $dayOffRequest->refresh();
            $dayOffRequest->load(['user', 'days.reviewer']);
            $this->syncDayOffRequestMessage($callbackQuery, $dayOffRequest, $action);

            return response()->json(['ok' => true, 'skipped' => 'dayoffday_already_reviewed']);
        }

        $this->answerCallback(
            $callbackQuery,
            $status === 'approved' ? 'Дата одобрена' : 'Дата отклонена',
        );

        $dayOffRequest->refresh();
        $dayOffRequest->load(['user', 'days.reviewer']);

        $handlerResult = $this->syncDayOffRequestMessage($callbackQuery, $dayOffRequest, $action)
            ? 'handled' : 'decision_saved_edit_failed';

        $this->logCallbackEvent($data, $reviewer !== null, true, true, $handlerResult);

        return response()->json(['ok' => true]);
    }

    private function syncDayOffRequestMessage(array $callbackQuery, DayOffRequest $dayOffRequest, string $action): bool
    {
        try {
            $edited = $this->editDayOffRequestMessage($callbackQuery, $dayOffRequest);

            if ($edited) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram day-off message edit failed', [
                'request_id' => $dayOffRequest->id,
                'callback_action' => $action,
                'exception' => class_basename($e),
            ]);

            return false;
        }

        Log::warning('Telegram day-off message edit was rejected', [
            'request_id' => $dayOffRequest->id,
            'callback_action' => $action,
        ]);

        return false;
    }

    private function canReviewRequests(?User $user): bool
    {
        return $user !== null
            && $user->status === 'approved'
            && $user->is_active !== false
            && in_array($user->role, ['admin', 'supervisor'], true);
    }

    private function logCallbackEvent(
        string $data,
        ?bool $telegramUserFound,
        ?bool $applicationFound,
        ?bool $authorizationPassed,
        string $handlerResult,
    ): void {
        $parts = explode(':', $data, 3);

        Log::info('Telegram callback result', [
            'callback_received' => true,
            'callback_type' => $parts[0] ?? 'unknown',
            'callback_action' => $parts[1] ?? 'unknown',
            'telegram_user_found' => $telegramUserFound,
            'application_found' => $applicationFound,
            'authorization_passed' => $authorizationPassed,
            'handler_result' => $handlerResult,
            'duration_ms' => $this->callbackStartedAt !== null
                ? round((hrtime(true) - $this->callbackStartedAt) / 1_000_000, 2)
                : null,
        ]);
    }

    private function editAccessMessage(
        array $callbackQuery,
        User $user,
        string $statusText,
        string $moderatorText
    ): bool {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (! $chatId || ! $messageId) {
            return false;
        }

        $fallbackHtml = implode("\n", [
            '<b>Заявка на доступ</b>',
            '',
            '<b>Имя:</b> '.e($user->name ?: 'Без имени'),
            '<b>Telegram:</b> '.e($user->telegram_username ? '@'.$user->telegram_username : '—'),
            '',
            $statusText,
            '<b>Решение принял:</b> '.e($moderatorText),
            '<b>Время:</b> '.($user->approved_at ?? $user->updated_at)->timezone('Europe/Rome')->format('d.m.Y H:i'),
        ]);

        $context = [
            'operation_key' => 'access:review-message:'.$user->id.':'.$user->status,
            'notification_type' => 'user_access', 'method' => 'editMessageText', 'bot' => 'work',
            'chat_id' => (string) $chatId, 'source_type' => 'User', 'source_id' => $user->id,
        ];
        $monitor = app(TelegramOutboundMonitor::class);
        $monitor->expect($context);

        return $monitor->withContext($context, fn () => app(TelegramBotService::class)->editMessage(
            chatId: (string) $chatId,
            messageId: (int) $messageId,
            forceLegacy: true,
            richMessage: app(TelegramRichMessageBuilder::class)->build(
                title: 'Заявка на доступ',
                status: strip_tags($statusText),
                fields: [
                    'Имя' => $user->name ?: 'Без имени',
                    'Telegram' => $user->telegram_username ? '@'.$user->telegram_username : '—',
                ],
                notice: 'Решение принял: '.$moderatorText,
            ),
            fallbackHtml: $fallbackHtml,
            replyMarkup: ['inline_keyboard' => []],
        ));
    }

    private function editDayOffRequestMessage(
        array $callbackQuery,
        DayOffRequest $dayOffRequest,
    ): bool {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (! $chatId || ! $messageId) {
            return false;
        }

        $user = $dayOffRequest->user;

        Carbon::setLocale('ru');

        $name = $user?->name ?: 'Неизвестный пользователь';

        $employeeText = $user?->telegram_id
            ? '<a href="tg://user?id='.e((string) $user->telegram_id).'">'.e($name).'</a>'
            : e($name);

        $dipText = isset($user?->dip)
            ? ($user->dip ? 'dip' : 'no dip')
            : '—';

        $sortedDays = $dayOffRequest->days->sortBy('date');
        $statuses = $sortedDays->pluck('status');
        $hasPending = $statuses->contains('pending');
        $hasApproved = $statuses->contains('approved');
        $hasRejected = $statuses->contains('rejected');
        $overallStatus = match (true) {
            $hasPending && ($hasApproved || $hasRejected) => 'частично рассмотрена',
            $hasPending => 'ожидает',
            $hasApproved && $hasRejected => 'смешанный результат',
            $hasApproved => 'одобрена',
            $hasRejected => 'отклонена',
            default => 'ожидает',
        };

        $message = [];
        $message[] = '📌 <b>Запрос на выходной</b>';
        $message[] = '';
        $message[] = '👤 <b>Сотрудник:</b> '.$employeeText;
        $message[] = '🏷️ <b>Dip:</b> '.e($dipText);
        $message[] = '';
        $message[] = '📅 <b>Даты:</b>';

        foreach ($sortedDays as $day) {
            $icon = match ($day->status) {
                'approved' => '✅',
                'rejected' => '❌',
                default => '⏳',
            };

            $date = Carbon::parse($day->date)->translatedFormat('d.m.Y (l)');

            $decision = match ($day->status) {
                'approved' => 'одобрено',
                'rejected' => 'отклонено',
                default => 'ожидает',
            };
            if ($day->status !== 'pending') {
                $reviewer = $day->reviewer?->name ?: 'руководитель не указан';
                $decision .= ' — '.$reviewer;

                if ($day->reviewed_at) {
                    $decision .= ', '.$day->reviewed_at->copy()->setTimezone('Europe/Rome')->format('d.m.Y H:i');
                }
            }

            $message[] = "{$icon} <b>{$date}</b> — ".e($decision);
        }

        $message[] = '';
        $message[] = '<b>Статус заявки:</b> '.e($overallStatus);

        if (filled($dayOffRequest->reason)) {
            $message[] = '';
            $message[] = '💬 <b>Причина:</b>';
            $message[] = '<blockquote>'.e(trim((string) $dayOffRequest->reason)).'</blockquote>';
        }

        $keyboard = [];

        foreach ($sortedDays as $day) {
            if ($day->status !== 'pending') {
                continue;
            }

            $date = Carbon::parse($day->date)->format('d.m');

            $keyboard[] = [
                [
                    'text' => "✅ {$date}",
                    'callback_data' => 'dayoffday:approve:'.$day->id,
                ],
                [
                    'text' => "❌ {$date}",
                    'callback_data' => 'dayoffday:reject:'.$day->id,
                ],
            ];
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => implode("\n", $message),
        ];

        if (! empty($keyboard)) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $keyboard,
            ];
        } else {
            $payload['reply_markup'] = [
                'inline_keyboard' => [],
            ];
        }

        $version = substr(hash('sha256', $sortedDays->map(fn ($day) => $day->id.':'.$day->status.':'.$day->reviewed_at?->toISOString())->implode('|')), 0, 16);
        $context = [
            'operation_key' => 'day_off:review-message:'.$dayOffRequest->id.':'.$version,
            'notification_type' => 'day_off', 'method' => 'editMessageText', 'bot' => 'work',
            'chat_id' => (string) $chatId,
            'source_type' => 'DayOffRequest', 'source_id' => $dayOffRequest->id,
        ];
        $monitor = app(TelegramOutboundMonitor::class);
        $monitor->expect($context);

        return $monitor->withContext($context, fn () => app(TelegramBotService::class)->editMessage(
            chatId: (string) $chatId,
            messageId: (int) $messageId,
            forceLegacy: true,
            richMessage: app(TelegramRichMessageBuilder::class)->build(
                title: 'Заявка на выходной',
                status: $overallStatus,
                fields: [
                    'Сотрудник' => $name,
                    'Dip' => $dipText,
                    'Даты' => $sortedDays->map(fn ($day) => Carbon::parse($day->date)->translatedFormat('d.m.Y (l)'))->implode(', '),
                ],
                body: $dayOffRequest->reason,
                results: $sortedDays->map(function ($day) {
                    $status = match ($day->status) {
                        'approved' => 'одобрено',
                        'rejected' => 'отклонено',
                        default => 'ожидает решения',
                    };

                    return Carbon::parse($day->date)->translatedFormat('d F').' — '.$status;
                })->all(),
                notice: 'Статус заявки: '.$overallStatus,
            ),
            fallbackHtml: $payload['text'],
            replyMarkup: $payload['reply_markup'],
        ));
    }

    private function answerCallback(array $callbackQuery, string $text): void
    {
        $callbackQueryId = $callbackQuery['id'] ?? null;

        if (! $callbackQueryId) {
            return;
        }

        try {
            $response = Http::withOptions([
                'force_ip_resolve' => config('services.telegram.ip_resolve', 'v6'),
            ])->timeout(2)
                ->connectTimeout(1)
                ->post($this->telegramApiUrl('answerCallbackQuery'), [
                    'callback_query_id' => $callbackQueryId,
                    'text' => $text,
                ]);

            if (! $response->successful() || data_get($response->json(), 'ok') === false) {
                $parts = explode(':', (string) ($callbackQuery['data'] ?? ''), 3);

                Log::warning('Telegram callback answer rejected', [
                    'callback_received' => true,
                    'callback_type' => $parts[0] ?? 'unknown',
                    'callback_action' => $parts[1] ?? 'unknown',
                    'handler_result' => 'answer_failed',
                    'http_status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            $parts = explode(':', (string) ($callbackQuery['data'] ?? ''), 3);

            Log::warning('Telegram callback answer failed', [
                'callback_received' => true,
                'callback_type' => $parts[0] ?? 'unknown',
                'callback_action' => $parts[1] ?? 'unknown',
                'handler_result' => 'answer_failed',
                'exception' => class_basename($e),
            ]);
        }
    }

    private function sendPrivateFallbackMessageStrict(array $message): void
    {
        if (! config('services.telegram.main_bot_auto_replies_enabled', false)) {
            return;
        }

        $chatId = data_get($message, 'chat.id');

        if (! $chatId) {
            return;
        }

        $messageKey = data_get($message, 'message_id')
            ?: sha1(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cacheKey = 'telegram_private_fallback_sent:'.$chatId.':'.$messageKey;

        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return;
        }

        try {
            $response = Http::withOptions([
                'force_ip_resolve' => config('services.telegram.ip_resolve', 'v6'),
            ])->timeout(3)
                ->connectTimeout(1)
                ->post($this->telegramApiUrl('sendMessage'), [
                    'chat_id' => $chatId,
                    'text' => "\u{042F} \u{043D}\u{0435} \u{043E}\u{0431}\u{0440}\u{0430}\u{0431}\u{0430}\u{0442}\u{044B}\u{0432}\u{0430}\u{044E} \u{043B}\u{0438}\u{0447}\u{043D}\u{044B}\u{0435} \u{0441}\u{043E}\u{043E}\u{0431}\u{0449}\u{0435}\u{043D}\u{0438}\u{044F}. \u{0418}\u{0441}\u{043F}\u{043E}\u{043B}\u{044C}\u{0437}\u{0443}\u{0439}\u{0442}\u{0435} \u{0440}\u{0430}\u{0431}\u{043E}\u{0447}\u{0438}\u{0439} \u{0447}\u{0430}\u{0442}.",
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);

            throw $e;
        }

        if (! $response->successful()) {
            Cache::forget($cacheKey);
        }
    }

    private function telegramApiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'
            .config('services.telegram.bot_token')
            .'/'
            .$method;
    }

    private function telegramUserText(array $from): string
    {
        $name = trim(collect([
            $from['first_name'] ?? null,
            $from['last_name'] ?? null,
        ])->filter()->implode(' '));

        if (! empty($from['username'])) {
            return '@'.$from['username'];
        }

        return $name ?: 'Неизвестно';
    }
}

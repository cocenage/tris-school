<?php

namespace App\Http\Controllers;

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Services\Telegram\TelegramUpdateIngestService;
use App\Services\TelegramUserNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramWorkWebhookController extends Controller
{


    public function __invoke(
        Request $request,
        string $secret,
        TelegramUpdateIngestService $ingestService
    ) {

        $configuredSecret = (string) config('services.telegram.work_webhook_secret');

        if ($configuredSecret === '' || ! hash_equals($configuredSecret, $secret)) {
            abort(403);
        }

        Log::info('Telegram work webhook received', [
            'update_id' => $request->input('update_id'),
            'has_callback_query' => $request->has('callback_query'),
        ]);

        $update = $request->all();

        if (isset($update['callback_query'])) {
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
    $this->sendPrivateFallbackMessage($message);

    return response()->json([
        'ok' => true,
        'skipped' => 'private_message_fallback',
    ]);
}

        $chatId = (string) data_get($message, 'chat.id');

        $allowedChatIds = config('services.telegram.work_allowed_chat_ids', []);

        if (! empty($allowedChatIds) && ! in_array($chatId, $allowedChatIds, true)) {
            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
                'chat_id' => $chatId,
            ]);
        }

        try {
            $savedMessage = $ingestService->ingest($update);

            return response()->json([
                'ok' => true,
                'message_id' => $savedMessage?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram work webhook failed', [
                'error' => $e->getMessage(),
                'update_id' => $update['update_id'] ?? null,
                'chat_id' => data_get($message, 'chat.id'),
                'message_id' => data_get($message, 'message_id'),
            ]);

            return response()->json(['ok' => false], 500);
        }
    }

    private function handleCallbackQuery(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';

        if (str_starts_with($data, 'access:')) {
            return $this->handleAccessCallback($callbackQuery);
        }

        if (str_starts_with($data, 'dayoffday:')) {
            return $this->handleDayOffDayCallback($callbackQuery);
        }

        return response()->json([
            'ok' => true,
            'skipped' => 'unknown_callback',
            'data' => $data,
        ]);
    }

    private function handleAccessCallback(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';
        $parts = explode(':', $data);

        if (count($parts) !== 3) {
            return response()->json(['ok' => true, 'skipped' => 'bad_callback_data']);
        }

        [, $action, $userId] = $parts;

        if (! $this->isAllowedCallbackChat($callbackQuery)) {
            $this->answerCallback($callbackQuery, 'Нет доступа к этому чату');

            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
            ]);
        }

        $moderator = $this->resolveModerator($callbackQuery, ['admin']);

        if (! $moderator) {
            $this->answerCallback($callbackQuery, 'Недостаточно прав');

            return response()->json([
                'ok' => true,
                'skipped' => 'moderator_not_allowed',
            ]);
        }

        $user = User::find($userId);

        if (! $user) {
            $this->answerCallback($callbackQuery, 'Пользователь не найден');

            return response()->json(['ok' => true, 'skipped' => 'user_not_found']);
        }

        $moderatorText = $this->telegramUserText($callbackQuery['from'] ?? []);

        if ($action === 'approve') {
            $user->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $moderator?->id,
            ])->save();

            app(TelegramUserNotificationService::class)->accessApproved($user);

            $statusText = '✅ <b>Доступ одобрен</b>';
            $callbackText = 'Доступ одобрен';
        } elseif ($action === 'reject') {
            $user->forceFill([
                'status' => 'rejected',
                'approved_by' => $moderator?->id,
            ])->save();

            $statusText = '❌ <b>Доступ отклонён</b>';
            $callbackText = 'Доступ отклонён';
        } else {
            $this->answerCallback($callbackQuery, 'Неизвестное действие');

            return response()->json(['ok' => true, 'skipped' => 'unknown_action']);
        }

        $this->editAccessMessage($callbackQuery, $user, $statusText, $moderatorText);
        $this->answerCallback($callbackQuery, $callbackText);

        return response()->json(['ok' => true]);
    }

    private function handleDayOffDayCallback(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';
        $parts = explode(':', $data);

        if (count($parts) !== 3) {
            return response()->json(['ok' => true, 'skipped' => 'bad_dayoffday_callback']);
        }

        [, $action, $dayId] = $parts;

        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->answerCallback($callbackQuery, 'Неизвестное действие');

            return response()->json(['ok' => true, 'skipped' => 'unknown_dayoffday_action']);
        }

        if (! $this->isAllowedCallbackChat($callbackQuery)) {
            $this->answerCallback($callbackQuery, 'Нет доступа к этому чату');

            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
            ]);
        }

        $reviewer = $this->resolveModerator($callbackQuery, ['admin', 'supervisor']);

        if (! $reviewer) {
            $this->answerCallback($callbackQuery, 'Недостаточно прав');

            return response()->json([
                'ok' => true,
                'skipped' => 'reviewer_not_allowed',
            ]);
        }

        $day = DayOffRequestDay::with([
            'request.user',
            'request.days',
        ])->find($dayId);

        if (! $day) {
            $this->answerCallback($callbackQuery, 'Дата не найдена');

            return response()->json(['ok' => true, 'skipped' => 'dayoffday_not_found']);
        }

        $dayOffRequest = $day->request;

        if (! $dayOffRequest) {
            $this->answerCallback($callbackQuery, 'Заявка не найдена');

            return response()->json(['ok' => true, 'skipped' => 'dayoff_request_not_found']);
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';

        $day->forceFill([
            'status' => $status,
        ])->save();

        $dayOffRequest->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->id,
        ])->save();

        $dayOffRequest->syncStatusAndNotify();

        $dayOffRequest->refresh();
        $dayOffRequest->load(['user', 'days']);

        $moderatorText = $this->telegramUserText($callbackQuery['from'] ?? []);

        $this->editDayOffRequestMessage(
            callbackQuery: $callbackQuery,
            dayOffRequest: $dayOffRequest,
            moderatorText: $moderatorText,
        );

        $this->answerCallback(
            $callbackQuery,
            $status === 'approved'
                ? 'Дата одобрена'
                : 'Дата отклонена'
        );

        return response()->json(['ok' => true]);
    }

    private function editAccessMessage(
        array $callbackQuery,
        User $user,
        string $statusText,
        string $moderatorText
    ): void {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (! $chatId || ! $messageId) {
            return;
        }

        Http::post($this->telegramApiUrl('editMessageText'), [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => implode("\n", [
                '👤 <b>Заявка на доступ</b>',
                '',
                '<b>Имя:</b> ' . e($user->name ?: 'Без имени'),
                '<b>Telegram:</b> ' . e($user->telegram_username ? '@' . $user->telegram_username : '—'),
                '',
                $statusText,
                '<b>Решение принял:</b> ' . e($moderatorText),
                '<b>Время:</b> ' . now()->format('d.m.Y H:i'),
            ]),
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Открыть пользователя',
                            'url' => url('/admin/education/users/' . $user->id . '/edit'),
                        ],
                    ],
                ],
            ],
        ]);
    }

private function editDayOffRequestMessage(
    array $callbackQuery,
    DayOffRequest $dayOffRequest,
    string $moderatorText
): void {
    $chatId = data_get($callbackQuery, 'message.chat.id');
    $messageId = data_get($callbackQuery, 'message.message_id');

    if (! $chatId || ! $messageId) {
        return;
    }

    $user = $dayOffRequest->user;

    Carbon::setLocale('ru');

    $name = $user?->name ?: 'Неизвестный пользователь';

    $employeeText = $user?->telegram_id
        ? '<a href="tg://user?id=' . e((string) $user->telegram_id) . '">' . e($name) . '</a>'
        : e($name);

    $dipText = isset($user?->dip)
        ? ($user->dip ? 'dip' : 'no dip')
        : '—';

    $sortedDays = $dayOffRequest->days->sortBy('date');

    $message = [];
    $message[] = '📌 <b>Запрос на выходной</b>';
    $message[] = '';
    $message[] = '👤 <b>Сотрудник:</b> ' . $employeeText;
    $message[] = '🏷️ <b>Dip:</b> ' . e($dipText);
    $message[] = '';
    $message[] = '📅 <b>Даты:</b>';

    foreach ($sortedDays as $day) {
        $icon = match ($day->status) {
            'approved' => '✅',
            'rejected' => '❌',
            default => '⏳',
        };

        $date = Carbon::parse($day->date)->translatedFormat('d.m.Y (l)');

        $message[] = "{$icon} <b>{$date}</b>";
    }

    if (filled($dayOffRequest->reason)) {
        $message[] = '';
        $message[] = '💬 <b>Причина:</b>';
        $message[] = '<blockquote>' . e(trim((string) $dayOffRequest->reason)) . '</blockquote>';
    }

    $message[] = '';
    $message[] = '<b>Последнее решение:</b> ' . e($moderatorText);
    $message[] = '<b>Время:</b> ' . now()->format('d.m.Y H:i');

    $keyboard = [];

    foreach ($sortedDays as $day) {
        if ($day->status !== 'pending') {
            continue;
        }

        $date = Carbon::parse($day->date)->format('d.m');

        $keyboard[] = [
            [
                'text' => "✅ {$date}",
                'callback_data' => 'dayoffday:approve:' . $day->id,
            ],
            [
                'text' => "❌ {$date}",
                'callback_data' => 'dayoffday:reject:' . $day->id,
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

    Http::post($this->telegramApiUrl('editMessageText'), $payload);
}

    private function answerCallback(array $callbackQuery, string $text): void
    {
        $callbackQueryId = $callbackQuery['id'] ?? null;

        if (! $callbackQueryId) {
            return;
        }

        Http::post($this->telegramApiUrl('answerCallbackQuery'), [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    private function sendPrivateFallbackMessage(array $message): void
{
    $chatId = data_get($message, 'chat.id');

    if (! $chatId) {
        return;
    }

    $cacheKey = 'telegram_private_fallback_sent:' . $chatId;

    if (Cache::has($cacheKey)) {
        return;
    }

    Cache::put($cacheKey, true, now()->addSeconds(60));

    Http::post($this->telegramApiUrl('sendMessage'), [
        'chat_id' => $chatId,
        'text' => implode("\n", [
            'Привет!',
            '',
            'Я не читаю личные сообщения, у меня нет ответа на ваш вопрос.',
            '',
            'Пожалуйста, выберите нужный тип заявки в академии:',
            'https://academy.trisservice.eu/applications',
        ]),
        'disable_web_page_preview' => true,
        'reply_markup' => [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Выбрать тип заявки',
                        'web_app' => [
                            'url' => 'https://academy.trisservice.eu/applications',
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

    private function isAllowedCallbackChat(array $callbackQuery): bool
    {
        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $allowedChatIds = array_map(
            'strval',
            config('services.telegram.work_allowed_chat_ids', [])
        );

        return $chatId !== ''
            && $allowedChatIds !== []
            && in_array($chatId, $allowedChatIds, true);
    }

    private function resolveModerator(array $callbackQuery, array $allowedRoles): ?User
    {
        $telegramId = data_get($callbackQuery, 'from.id');

        if (! $telegramId) {
            return null;
        }

        return User::query()
            ->where('telegram_id', (string) $telegramId)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->whereIn('role', $allowedRoles)
            ->first();
    }

    private function telegramApiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'
            . config('services.telegram.bot_token')
            . '/'
            . $method;
    }

    private function telegramUserText(array $from): string
    {
        $name = trim(collect([
            $from['first_name'] ?? null,
            $from['last_name'] ?? null,
        ])->filter()->implode(' '));

        if (! empty($from['username'])) {
            return '@' . $from['username'];
        }

        return $name ?: 'Неизвестно';
    }
}
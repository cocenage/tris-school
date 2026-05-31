<?php

namespace App\Http\Controllers;

use App\Models\DayOffRequest;
use App\Models\User;
use App\Services\Telegram\TelegramUpdateIngestService;
use App\Services\TelegramUserNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWorkWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        TelegramUpdateIngestService $ingestService
    ) {
        if ($secret !== config('services.telegram.work_webhook_secret')) {
            abort(403);
        }

        $update = $request->all();

Log::info('Telegram update received', [
    'keys' => array_keys($update),
    'callback_data' => data_get($update, 'callback_query.data'),
    'chat_id' => data_get($update, 'callback_query.message.chat.id'),
]);

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
                'update' => $update,
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

        if (str_starts_with($data, 'dayoff:')) {
            return $this->handleDayOffCallback($callbackQuery);
        }

        return response()->json([
            'ok' => true,
            'skipped' => 'unknown_callback',
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

        $chatId = (string) data_get($callbackQuery, 'message.chat.id');
        $allowedChatIds = config('services.telegram.work_allowed_chat_ids', []);

        if (! empty($allowedChatIds) && ! in_array($chatId, $allowedChatIds, true)) {
            $this->answerCallback($callbackQuery, 'Нет доступа к этому чату');

            return response()->json([
                'ok' => true,
                'skipped' => 'chat_not_allowed',
                'chat_id' => $chatId,
            ]);
        }

        $user = User::find($userId);

        if (! $user) {
            $this->answerCallback($callbackQuery, 'Пользователь не найден');

            return response()->json(['ok' => true, 'skipped' => 'user_not_found']);
        }

        $fromTelegramId = data_get($callbackQuery, 'from.id');

        $moderator = $fromTelegramId
            ? User::where('telegram_id', (string) $fromTelegramId)->first()
            : null;

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

    private function handleDayOffCallback(array $callbackQuery)
    {
        $data = $callbackQuery['data'] ?? '';
        $parts = explode(':', $data);

        if (count($parts) !== 3) {
            return response()->json(['ok' => true, 'skipped' => 'bad_dayoff_callback']);
        }

        [, $action, $requestId] = $parts;

        $dayOffRequest = DayOffRequest::with(['user', 'days'])->find($requestId);

        if (! $dayOffRequest) {
            $this->answerCallback($callbackQuery, 'Заявка не найдена');

            return response()->json(['ok' => true, 'skipped' => 'dayoff_not_found']);
        }

        if (! in_array($action, ['approve', 'reject'], true)) {
            $this->answerCallback($callbackQuery, 'Неизвестное действие');

            return response()->json(['ok' => true, 'skipped' => 'unknown_dayoff_action']);
        }

        $fromTelegramId = data_get($callbackQuery, 'from.id');

        $reviewer = $fromTelegramId
            ? User::where('telegram_id', (string) $fromTelegramId)->first()
            : null;

        $status = $action === 'approve' ? 'approved' : 'rejected';

        $dayOffRequest->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->id,
        ])->save();

        $dayOffRequest->days()->update([
            'status' => $status,
        ]);

        $dayOffRequest->syncStatusAndNotify();

        $dayOffRequest->refresh();
        $dayOffRequest->load(['user', 'days']);

        $moderatorText = $this->telegramUserText($callbackQuery['from'] ?? []);

        $statusText = $status === 'approved'
            ? '✅ <b>Заявка одобрена</b>'
            : '❌ <b>Заявка отклонена</b>';

        $this->editDayOffMessage($callbackQuery, $dayOffRequest, $statusText, $moderatorText);

        $this->answerCallback(
            $callbackQuery,
            $status === 'approved' ? 'Заявка одобрена' : 'Заявка отклонена'
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
                '<b>Telegram ID:</b> ' . e((string) $user->telegram_id),
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
                            'url' => url('/admin/users/' . $user->id . '/edit'),
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function editDayOffMessage(
        array $callbackQuery,
        DayOffRequest $dayOffRequest,
        string $statusText,
        string $moderatorText
    ): void {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (! $chatId || ! $messageId) {
            return;
        }

        $user = $dayOffRequest->user;

        Carbon::setLocale('ru');

        $formattedDates = $dayOffRequest->days
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->translatedFormat('d.m.Y (l)'))
            ->implode("\n• ");

        $name = $user?->name ?: 'Неизвестный пользователь';

        $dipText = isset($user?->dip)
            ? ($user->dip ? 'dip' : 'no dip')
            : '—';

        $adminUrl = url('/admin/day-off-requests/' . $dayOffRequest->id . '/edit');

        Http::post($this->telegramApiUrl('editMessageText'), [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => implode("\n", [
                '📌 <b>Запрос на выходной</b>',
                '',
                '👤 <b>Сотрудник:</b> ' . e($name),
                '🏷️ <b>Dip:</b> ' . e($dipText),
                '📅 <b>Даты:</b>',
                '• ' . $formattedDates,
                '',
                '💬 <b>Причина:</b>',
                '<blockquote>' . e(trim((string) $dayOffRequest->reason)) . '</blockquote>',
                '',
                $statusText,
                '<b>Решение принял:</b> ' . e($moderatorText),
                '<b>Время:</b> ' . now()->format('d.m.Y H:i'),
                '',
                "⛓️ <a href='{$adminUrl}'><b>Открыть в админке</b></a>",
            ]),
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Открыть в админке',
                            'url' => $adminUrl,
                        ],
                    ],
                ],
            ],
        ]);
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
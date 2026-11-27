<?php

namespace App\Services;

use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramRichMessageBuilder;
use Illuminate\Support\Facades\Log;

class TelegramUserNotificationService
{
    public function accessRequested(User $user): void
    {
        if ($user->telegram_access_requested_notified_at) {
            return;
        }

        if ($user->status !== 'pending') {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        $adminChatId = config('services.telegram.admin_chat_id');
        $adminThreadId = config('services.telegram.admin_thread_id');

        if (! $botToken || ! $adminChatId) {
            Log::warning('Telegram admin notification config missing.');

            return;
        }

        $context = $this->accessContext($user, 'requested', (string) $adminChatId, $adminThreadId);
        app(TelegramOutboundMonitor::class)->expect($context);

        $fields = [
            'Имя' => $this->userName($user),
            'Telegram' => $this->telegramUsername($user),
            'TRIS ID' => $user->tris_id ?? '—',
        ];

        $registration = $user->registration_data ?? [];
        foreach (['phone' => 'Телефон', 'requested_role' => 'Запрошенная должность', 'city' => 'Город / зона', 'supervisor' => 'Супервайзер', 'work_started_at' => 'Начало работы'] as $key => $label) {
            if (isset($registration[$key])) {
                $value = (string) $registration[$key];
                $fields[$label] = $key === 'requested_role'
                    ? (['cleaner' => 'Клинер', 'supervisor' => 'Супервайзер'][$value] ?? $value)
                    : $value;
            }
        }

        $payload = [
            'chat_id' => $adminChatId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => implode("\n", [
                '👤 <b>Новая заявка на доступ</b>',
                '',
                ...collect($fields)->map(fn ($value, $label) => '<b>'.$this->escape($label).':</b> '.$this->escape($value))->values()->all(),
                '<b>Статус:</b> ожидает одобрения',
                '',
                'Пользователь ждёт подтверждения доступа к сайту.',
            ]),
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Одобрить',
                            'callback_data' => 'access:approve:'.$user->id,
                        ],
                        [
                            'text' => '❌ Отказать',
                            'callback_data' => 'access:reject:'.$user->id,
                        ],
                    ],
                    [
                        [
                            'text' => 'Открыть пользователя',
                            'url' => $this->userAdminUrl($user),
                        ],
                    ],
                ],
            ],
        ];

        if ($adminThreadId) {
            $payload['message_thread_id'] = $adminThreadId;
        }

        $rich = app(TelegramRichMessageBuilder::class)->build(
            title: 'Заявка на доступ',
            status: 'Ожидает одобрения',
            fields: $fields,
            notice: 'Пользователь ждёт подтверждения доступа к сайту.',
        );

        $messageId = app(TelegramOutboundMonitor::class)->withContext($context, fn () => app(TelegramBotService::class)->sendRichMessage(
            chatId: (string) $adminChatId,
            richMessage: $rich,
            fallbackHtml: $payload['text'],
            threadId: $adminThreadId ? (string) $adminThreadId : null,
            replyMarkup: $payload['reply_markup'],
        ));

        if (! $messageId) {
            Log::warning('Telegram access requested notification failed.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
            ]);

            return;
        }

        $user->forceFill([
            'telegram_access_requested_notified_at' => now(),
        ])->saveQuietly();
    }

    public function accessPending(User $user): void
    {
        if (! $user->telegram_id) {
            return;
        }

        if ($user->telegram_access_pending_notified_at) {
            return;
        }

        if ($user->status !== 'pending') {
            return;
        }

        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            Log::warning('Telegram bot token is not configured.');

            return;
        }

        $context = $this->accessContext($user, 'pending', (string) $user->telegram_id);
        app(TelegramOutboundMonitor::class)->expect($context);

        if (! app(TelegramOutboundMonitor::class)->withContext($context, fn () => $this->sendAccessRichNotice(
            user: $user,
            title: 'Заявка на доступ отправлена',
            status: 'Ожидает подтверждения',
            fallbackHtml: implode("\n", [
                '⏳ <b>Заявка на доступ отправлена</b>',
                '',
                'Мы получили вашу заявку.',
                'Пожалуйста, не отправляйте её повторно.',
                '',
                'Когда администратор подтвердит доступ, мы пришлём сообщение здесь.',
            ]),
        ))) {
            Log::warning('Telegram access pending notification failed.', ['user_id' => $user->id]);

            return;
        }

        $user->forceFill([
            'telegram_access_pending_notified_at' => now(),
        ])->saveQuietly();
    }

    public function accessApproved(User $user): void
    {
        if (! $user->telegram_id) {
            return;
        }

        if ($user->telegram_access_approved_notified_at) {
            return;
        }

        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            Log::warning('Telegram bot token is not configured.');

            return;
        }

        $context = $this->accessContext($user, 'approved', (string) $user->telegram_id);
        app(TelegramOutboundMonitor::class)->expect($context);

        if (! app(TelegramOutboundMonitor::class)->withContext($context, fn () => $this->sendAccessRichNotice(
            user: $user,
            title: 'Доступ одобрен',
            status: 'Одобрено',
            fallbackHtml: implode("\n", [
                '✅ <b>Доступ одобрен</b>',
                '',
                'Ваш аккаунт подтверждён.',
                'Теперь вы можете войти в приложение.',
            ]),
            replyMarkup: [
                'inline_keyboard' => [[[
                    'text' => 'Открыть приложение',
                    'url' => route('landing'),
                ]]],
            ],
        ))) {
            Log::warning('Telegram access approved notification failed.', ['user_id' => $user->id]);

            return;
        }

        $user->forceFill([
            'telegram_access_approved_notified_at' => now(),
        ])->saveQuietly();
    }

    protected function userAdminUrl(User $user): string
    {
        return url('/admin/supervisor/users/'.$user->id.'/edit');
    }

    private function accessContext(User $user, string $kind, string $chatId, ?string $threadId = null): array
    {
        return [
            'operation_key' => 'access:'.$kind.':'.$user->id,
            'notification_type' => 'user_access', 'bot' => 'work',
            'chat_id' => $chatId, 'message_thread_id' => $threadId,
            'source_type' => 'User', 'source_id' => $user->id,
        ];
    }

    private function sendAccessRichNotice(
        User $user,
        string $title,
        string $status,
        string $fallbackHtml,
        ?array $replyMarkup = null,
    ): bool {
        return (bool) app(TelegramBotService::class)->sendRichMessage(
            chatId: (string) $user->telegram_id,
            richMessage: app(TelegramRichMessageBuilder::class)->build(
                title: $title,
                status: $status,
            ),
            fallbackHtml: $fallbackHtml,
            replyMarkup: $replyMarkup,
        );
    }

    protected function userName(User $user): string
    {
        $name = $user->name ?: trim(collect([
            $user->telegram_first_name,
            $user->telegram_last_name,
        ])->filter()->implode(' '));

        return $name ?: 'Без имени';
    }

    protected function telegramUsername(User $user): string
    {
        return $user->telegram_username
            ? '@'.$user->telegram_username
            : '—';
    }

    protected function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

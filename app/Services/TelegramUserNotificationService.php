<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
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

        if (!$botToken || !$adminChatId) {
            Log::warning('Telegram admin notification config missing.');
            return;
        }

        $payload = [
            'chat_id' => $adminChatId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'text' => implode("\n", [
                '👤 <b>Новая заявка на доступ</b>',
                '',
                '<b>Имя:</b> ' . $this->escape($this->userName($user)),
                '<b>Telegram:</b> ' . $this->escape($this->telegramUsername($user)),
                '<b>Telegram ID:</b> ' . $this->escape((string) $user->telegram_id),
                '<b>Статус:</b> ожидает одобрения',
                '',
                'Пользователь ждёт подтверждения доступа к сайту.',
            ]),
            'reply_markup' => [
                'inline_keyboard' => [
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

        $response = Http::timeout(10)->post(
            'https://api.telegram.org/bot' . $botToken . '/sendMessage',
            $payload
        );

        if (!$response->successful()) {
            Log::warning('Telegram access requested notification failed.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $user->forceFill([
            'telegram_access_requested_notified_at' => now(),
        ])->saveQuietly();
    }

    public function accessPending(User $user): void
    {
        if (!$user->telegram_id) {
            return;
        }

        if ($user->telegram_access_pending_notified_at) {
            return;
        }

        if ($user->status !== 'pending') {
            return;
        }

        $botToken = config('services.telegram.bot_token');

        if (!$botToken) {
            Log::warning('Telegram bot token is not configured.');
            return;
        }

        $response = Http::timeout(10)->post(
            'https://api.telegram.org/bot' . $botToken . '/sendMessage',
            [
                'chat_id' => $user->telegram_id,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'text' => implode("\n", [
                    '⏳ <b>Заявка на доступ отправлена</b>',
                    '',
                    'Мы получили вашу заявку.',
                    'Пожалуйста, не отправляйте её повторно.',
                    '',
                    'Когда администратор подтвердит доступ, мы пришлём сообщение здесь.',
                ]),
            ]
        );

        if (!$response->successful()) {
            Log::warning('Telegram access pending notification failed.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $user->forceFill([
            'telegram_access_pending_notified_at' => now(),
        ])->saveQuietly();
    }

    public function accessApproved(User $user): void
    {
        if (!$user->telegram_id) {
            return;
        }

        if ($user->telegram_access_approved_notified_at) {
            return;
        }

        $botToken = config('services.telegram.bot_token');

        if (!$botToken) {
            Log::warning('Telegram bot token is not configured.');
            return;
        }

        $response = Http::timeout(10)->post(
            'https://api.telegram.org/bot' . $botToken . '/sendMessage',
            [
                'chat_id' => $user->telegram_id,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'text' => implode("\n", [
                    '✅ <b>Доступ одобрен</b>',
                    '',
                    'Ваш аккаунт подтверждён.',
                    'Теперь вы можете войти в приложение.',
                ]),
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'Открыть приложение',
                                'url' => route('landing'),
                            ],
                        ],
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            Log::warning('Telegram access approved notification failed.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $user->forceFill([
            'telegram_access_approved_notified_at' => now(),
        ])->saveQuietly();
    }

    protected function userAdminUrl(User $user): string
    {
        return url('/admin/education/users/' . $user->id . '/edit');
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
            ? '@' . $user->telegram_username
            : '—';
    }

    protected function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
<?php

namespace App\Services;

use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramRichMessageBuilder;
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
                '<b>Статус:</b> ожидает одобрения',
                '',
                'Пользователь ждёт подтверждения доступа к сайту.',
            ]),
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Одобрить',
                            'callback_data' => 'access:approve:' . $user->id,
                        ],
                        [
                            'text' => '❌ Отказать',
                            'callback_data' => 'access:reject:' . $user->id,
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
            fields: [
                'Имя' => $this->userName($user),
                'Telegram' => $this->telegramUsername($user),
            ],
            notice: 'Пользователь ждёт подтверждения доступа к сайту.',
        );

        $messageId = app(TelegramBotService::class)->sendRichMessage(
            chatId: (string) $adminChatId,
            richMessage: $rich,
            fallbackHtml: $payload['text'],
            threadId: $adminThreadId ? (string) $adminThreadId : null,
            replyMarkup: $payload['reply_markup'],
        );

        if (!$messageId) {
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

        if (! $this->sendAccessRichNotice(
            user: $user,
            title: 'Заявка на доступ отправлена',
            status: 'Ожидает подтверждения',
            fallbackHtml: implode("\n", [
                'вЏі <b>Р—Р°СЏРІРєР° РЅР° РґРѕСЃС‚СѓРї РѕС‚РїСЂР°РІР»РµРЅР°</b>',
                '',
                'РњС‹ РїРѕР»СѓС‡РёР»Рё РІР°С€Сѓ Р·Р°СЏРІРєСѓ.',
                'РџРѕР¶Р°Р»СѓР№СЃС‚Р°, РЅРµ РѕС‚РїСЂР°РІР»СЏР№С‚Рµ РµС‘ РїРѕРІС‚РѕСЂРЅРѕ.',
                '',
                'РљРѕРіРґР° Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂ РїРѕРґС‚РІРµСЂРґРёС‚ РґРѕСЃС‚СѓРї, РјС‹ РїСЂРёС€Р»С‘Рј СЃРѕРѕР±С‰РµРЅРёРµ Р·РґРµСЃСЊ.',
            ]),
        )) {
            Log::warning('Telegram access pending notification failed.', ['user_id' => $user->id]);
            return;
        }

        $user->forceFill([
            'telegram_access_pending_notified_at' => now(),
        ])->saveQuietly();

        return;

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

        if (! $this->sendAccessRichNotice(
            user: $user,
            title: 'Доступ одобрен',
            status: 'Одобрено',
            fallbackHtml: implode("\n", [
                'вњ… <b>Р”РѕСЃС‚СѓРї РѕРґРѕР±СЂРµРЅ</b>',
                '',
                'Р’Р°С€ Р°РєРєР°СѓРЅС‚ РїРѕРґС‚РІРµСЂР¶РґС‘РЅ.',
                'РўРµРїРµСЂСЊ РІС‹ РјРѕР¶РµС‚Рµ РІРѕР№С‚Рё РІ РїСЂРёР»РѕР¶РµРЅРёРµ.',
            ]),
            replyMarkup: [
                'inline_keyboard' => [[[
                    'text' => 'РћС‚РєСЂС‹С‚СЊ РїСЂРёР»РѕР¶РµРЅРёРµ',
                    'url' => route('landing'),
                ]]],
            ],
        )) {
            Log::warning('Telegram access approved notification failed.', ['user_id' => $user->id]);
            return;
        }

        $user->forceFill([
            'telegram_access_approved_notified_at' => now(),
        ])->saveQuietly();

        return;

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
            ? '@' . $user->telegram_username
            : '—';
    }

    protected function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

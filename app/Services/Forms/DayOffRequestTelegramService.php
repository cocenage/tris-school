<?php

namespace App\Services\Forms;

use App\Models\DayOffRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DayOffRequestTelegramService
{
    public function sendResult(DayOffRequest $request): void
    {
        $request->loadMissing(['user', 'days']);

        $chatId = $request->user?->telegram_id;
        $token = config('services.telegram.bot_token');

        if (! $chatId || ! $token) {
            Log::warning('Telegram notification skipped: missing credentials', [
                'request_id' => $request->id,
                'chat_id' => $chatId,
                'token_exists' => filled($token),
            ]);

            return;
        }

        Carbon::setLocale('ru');

        $title = match ($request->status) {
            'approved' => '✅ <b>Заявка одобрена</b>',
            'rejected' => '❌ <b>Заявка отклонена</b>',
            'partially_approved' => '🟡 <b>Заявка частично одобрена</b>',
            default => '📌 <b>Статус заявки обновлён</b>',
        };

        $description = match ($request->status) {
            'approved' => 'Все выбранные даты согласованы.',
            'rejected' => 'К сожалению, выбранные даты не удалось согласовать.',
            'partially_approved' => 'Часть дат удалось согласовать, а часть — нет.',
            default => 'Статус заявки был обновлён.',
        };

        $message = [];
        $message[] = $title;
        $message[] = '';
        $message[] = $description;
        $message[] = '';
        $message[] = '📅 <b>Даты:</b>';

        foreach ($request->days->sortBy('date') as $day) {
            $date = Carbon::parse($day->date)->translatedFormat('d F');

            $dayStatus = match ($day->status) {
                'approved' => '✅',
                'rejected' => '❌',
                default => '⏳',
            };

            $message[] = "{$dayStatus} <b>{$date}</b>";

            if ($day->admin_comment) {
                $message[] = '— <i>' . e($day->admin_comment) . '</i>';
            }
        }

        if ($request->admin_comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий администратора</b>';
            $message[] = e($request->admin_comment);
        }

        $profileUrl = config('services.day_off.profile_url');
        $adminChatUrl = config('services.day_off.admin_chat_url');

        $keyboard = [
            [
                [
                    'text' => '📋 Мои заявки',
                    'url' => $profileUrl,
                ],
                [
                    'text' => '💬 Чат с админом',
                    'url' => $adminChatUrl,
                ],
            ],
        ];

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => implode("\n", $message),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ]
        );

        if ($response->failed()) {
            Log::error('Telegram send failed', [
                'request_id' => $request->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
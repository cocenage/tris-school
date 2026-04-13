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
            return;
        }

        Carbon::setLocale('ru');

        $title = match ($request->status) {
            'approved' => '✅ <b>Ваша заявка одобрена</b>',
            'rejected' => '❌ <b>Ваша заявка отклонена</b>',
            'partially_approved' => '🟡 <b>Ваша заявка частично одобрена</b>',
            default => '📌 <b>Статус вашей заявки обновлён</b>',
        };

        $message = [];
        $message[] = $title;
        $message[] = '';

        foreach ($request->days->sortBy('date') as $day) {
            $dayStatus = match ($day->status) {
                'approved' => '✅ Одобрено',
                'rejected' => '❌ Отказ',
                default => '⏳ На рассмотрении',
            };

            $date = Carbon::parse($day->date)->translatedFormat('d F Y');

            $message[] = '• <b>' . e($date) . '</b> — ' . $dayStatus;

            if ($day->admin_comment) {
                $message[] = '<i>Причина: ' . e($day->admin_comment) . '</i>';
            }

            $message[] = '';
        }

        if ($request->admin_comment) {
            $message[] = '💬 <b>Комментарий администратора:</b>';
            $message[] = e($request->admin_comment);
        }

        $miniAppUrl = 'https://academy.trisservice.eu/profile/weekend';

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => implode("\n", $message),
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '📋 Мои заявки',
                                'web_app' => [
                                    'url' => $miniAppUrl,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
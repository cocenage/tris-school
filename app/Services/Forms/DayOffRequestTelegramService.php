<?php

namespace App\Services\Forms;

use App\Models\DayOffRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DayOffRequestTelegramService
{
    public function sendCreated(DayOffRequest $request): void
    {
        $request->loadMissing(['user', 'days']);

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id_formweekend');
        $threadId = config('services.telegram.thread_id_formweekend');

        if (blank($token) || blank($chatId)) {
            Log::warning('Day off created telegram skipped: missing credentials', [
                'request_id' => $request->id,
                'token_exists' => filled($token),
                'chat_id' => $chatId,
            ]);

            return;
        }

        Carbon::setLocale('ru');
        $user = $request->user;
        $name = $user?->name ?: 'Неизвестный пользователь';
        $employeeText = $user?->telegram_id
            ? '<a href="tg://user?id=' . e((string) $user->telegram_id) . '">' . e($name) . '</a>'
            : e($name);
        $dipText = isset($user?->dip) ? ($user->dip ? 'dip' : 'no dip') : '—';
        $sortedDays = $request->days->sortBy('date');
        $formattedDates = $sortedDays
            ->map(fn ($day) => Carbon::parse($day->date)->translatedFormat('d.m.Y (l)'))
            ->map(fn ($date) => '• ' . $date)
            ->implode("\n");

        $text = "📌 <b>Новый запрос на выходной</b>\n\n";
        $text .= "👤 <b>Сотрудник:</b> {$employeeText}\n";
        $text .= "🏷️ <b>Dip:</b> " . e($dipText) . "\n";
        $text .= "📅 <b>Даты:</b>\n{$formattedDates}\n\n";

        if (filled($request->reason)) {
            $text .= "💬 <b>Причина:</b>\n";
            $text .= "<blockquote>" . e(trim((string) $request->reason)) . "</blockquote>\n\n";
        }

        $text .= "⏳ <b>Статус:</b> ожидает решения";

        $keyboard = $sortedDays->map(function ($day) {
            $date = Carbon::parse($day->date)->format('d.m');

            return [
                ['text' => "✅ {$date}", 'callback_data' => 'dayoffday:approve:' . $day->id],
                ['text' => "❌ {$date}", 'callback_data' => 'dayoffday:reject:' . $day->id],
            ];
        })->values()->all();

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];

        if (filled($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload,
        );

        if ($response->failed()) {
            Log::error('Day off created telegram send failed', [
                'request_id' => $request->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Telegram rejected day-off notification.');
        }
    }

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

            throw new \RuntimeException('Telegram rejected day-off result notification.');
        }
    }
}

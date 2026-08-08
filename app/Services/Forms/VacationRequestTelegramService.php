<?php

namespace App\Services\Forms;

use App\Models\VacationRequest;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramRichMessageBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class VacationRequestTelegramService
{
    public function sendResult(VacationRequest $request): void
    {
        $request->loadMissing(['user', 'days']);

        $chatId = $request->user?->telegram_id;
        $token = config('services.telegram.bot_token');

        if (! $chatId || ! $token) {
            Log::warning('Vacation telegram notification skipped: missing credentials', [
                'request_id' => $request->id,
                'chat_id' => $chatId,
                'token_exists' => filled($token),
            ]);

            return;
        }

        Carbon::setLocale('ru');

        $title = match ($request->status) {
            'approved' => '✅ <b>Заявка на отпуск одобрена</b>',
            'rejected' => '❌ <b>Заявка на отпуск отклонена</b>',
            'partially_approved' => '🟡 <b>Заявка на отпуск частично одобрена</b>',
            default => '📌 <b>Статус заявки на отпуск обновлён</b>',
        };

        $description = match ($request->status) {
            'approved' => 'Все выбранные даты отпуска согласованы.',
            'rejected' => 'К сожалению, выбранные даты отпуска не удалось согласовать.',
            'partially_approved' => 'Часть дат отпуска удалось согласовать, а часть — нет.',
            default => 'Статус заявки на отпуск был обновлён.',
        };

        $message = [];
        $message[] = $title;
        $message[] = '';
        $message[] = $description;
        $message[] = '';
        $message[] = '🏖 <b>Даты отпуска:</b>';

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

        $profileUrl = config('services.vacation.profile_url');
        $adminChatUrl = config('services.vacation.admin_chat_url');

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

        $fallbackHtml = implode("\n", $message);
        $rich = app(TelegramRichMessageBuilder::class)->build(
            title: 'Заявка на отпуск',
            status: match ($request->status) {
                'approved' => 'Одобрено',
                'rejected' => 'Отклонено',
                'partially_approved' => 'Частично одобрено',
                default => 'Статус обновлён',
            },
            fields: [
                'Даты отпуска' => $request->days->sortBy('date')->map(fn ($day) => Carbon::parse($day->date)->translatedFormat('d F'))->implode(', '),
            ],
            body: $description,
            results: $request->days->sortBy('date')->map(function ($day) {
                $status = match ($day->status) {
                    'approved' => 'одобрено',
                    'rejected' => 'отклонено',
                    default => 'ожидает решения',
                };

                return Carbon::parse($day->date)->translatedFormat('d F') . ' — ' . $status;
            })->all(),
        );

        $messageId = app(TelegramBotService::class)->sendRichMessage(
            chatId: (string) $chatId,
            richMessage: $rich,
            fallbackHtml: $fallbackHtml,
            replyMarkup: ['inline_keyboard' => $keyboard],
        );

        if (! $messageId) {
            Log::error('Vacation telegram send failed', [
                'request_id' => $request->id,
            ]);

            throw new \RuntimeException('Telegram rejected vacation result notification.');
        }
    }

    public function sendCreated(VacationRequest $request): void
{
    $request->loadMissing(['user', 'days']);

    $token = config('services.telegram.bot_token');
    $chatId = config('services.telegram.chat_id_vacation');
    $threadId = config('services.telegram.thread_id_vacation');

    if (blank($token) || blank($chatId)) {
        Log::warning('Vacation created telegram skipped: missing credentials', [
            'request_id' => $request->id,
            'token_exists' => filled($token),
            'chat_id' => $chatId,
            'thread_id' => $threadId,
        ]);

        return;
    }

    Carbon::setLocale('ru');

    $dates = $request->days
        ->sortBy('date')
        ->map(fn ($day) => Carbon::parse($day->date)->translatedFormat('d.m.Y (l)'))
        ->implode("\n• ");

    $user = $request->user;
    $name = $user?->name ?: 'Неизвестный пользователь';

    $adminUrl = url('/admin/vacation-requests/' . $request->id . '/edit');

    $text = "🏖 <b>Новая заявка на отпуск</b>\n\n";
    $text .= "👤 <b>Сотрудник:</b> " . e($name) . "\n";
    $text .= "📅 <b>Даты:</b>\n• {$dates}\n\n";
    $text .= "💬 <b>Комментарий:</b>\n<blockquote>" . e((string) $request->reason) . "</blockquote>\n\n";
    $text .= "⛓️ <a href='{$adminUrl}'><b>Открыть в админке</b></a>";

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if (filled($threadId)) {
        $payload['message_thread_id'] = (int) $threadId;
    }

    $rich = app(TelegramRichMessageBuilder::class)->build(
        title: 'Новая заявка на отпуск',
        status: 'Ожидает решения',
        fields: [
            'Сотрудник' => $name,
            'Даты' => $request->days->sortBy('date')->map(fn ($day) => Carbon::parse($day->date)->translatedFormat('d.m.Y (l)'))->implode(', '),
        ],
        body: (string) $request->reason,
        notice: 'Заявка ожидает решения администратора или супервайзера.',
    );

    $messageId = app(TelegramBotService::class)->sendRichMessage(
        chatId: (string) $chatId,
        richMessage: $rich,
        fallbackHtml: $text,
        threadId: filled($threadId) ? (string) $threadId : null,
    );

    if (! $messageId) {
        Log::error('Vacation created telegram send failed', [
            'request_id' => $request->id,
        ]);

        throw new \RuntimeException('Telegram rejected vacation notification.');
    }
}
}

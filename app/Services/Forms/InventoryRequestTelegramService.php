<?php

namespace App\Services\Forms;

use App\Models\InventoryRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InventoryRequestTelegramService
{
    public function sendCreated(InventoryRequest $request): void
    {
        $request->loadMissing(['user', 'items']);

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id_inventory');
        $threadId = config('services.telegram.thread_id_inventory');

        if (! $token || ! $chatId) {
            Log::warning('Inventory created telegram skipped: missing credentials', [
                'request_id' => $request->id,
                'chat_id' => $chatId,
                'thread_id' => $threadId,
                'token_exists' => filled($token),
            ]);

            return;
        }

        Carbon::setLocale('ru');

        $message = [];
        $message[] = '📦 <b>Новая заявка на инвентарь</b>';
        $message[] = '';
        $message[] = '👤 <b>Сотрудник:</b> ' . e($request->user?->name ?? 'Без имени');
        $message[] = '🆔 <b>Заявка:</b> #' . $request->id;
        $message[] = '🕒 <b>Отправлено:</b> ' . ($request->requested_at?->translatedFormat('d F Y, H:i') ?? $request->created_at?->translatedFormat('d F Y, H:i'));
        $message[] = '';
        $message[] = '📋 <b>Позиции:</b>';

        foreach ($request->items as $item) {
            $message[] = '• <b>' . e($item->item_name) . '</b> — ' . (int) $item->requested_qty;
        }

        if ($request->comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий</b>';
            $message[] = e($request->comment);
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => implode("\n", $message),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (filled($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::error('Inventory created telegram send failed', [
                'request_id' => $request->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    public function sendResult(InventoryRequest $request): void
    {
        $request->loadMissing(['user', 'items']);

        $chatId = $request->user?->telegram_id;
        $token = config('services.telegram.bot_token');

        if (! $chatId || ! $token) {
            Log::warning('Inventory result telegram skipped: missing credentials', [
                'request_id' => $request->id,
                'chat_id' => $chatId,
                'token_exists' => filled($token),
            ]);

            return;
        }

        Carbon::setLocale('ru');

        $title = match ($request->status) {
            'approved' => '✅ <b>Заявка на инвентарь одобрена</b>',
            'rejected' => '❌ <b>Заявка на инвентарь отклонена</b>',
            'partially_approved' => '🟡 <b>Заявка на инвентарь частично одобрена</b>',
            default => '📌 <b>Статус заявки обновлён</b>',
        };

        $description = match ($request->status) {
            'approved' => 'Все позиции по заявке согласованы.',
            'rejected' => 'К сожалению, заявку не удалось согласовать.',
            'partially_approved' => 'Часть позиций согласована, часть — нет.',
            default => 'Статус заявки был обновлён.',
        };

        $message = [];
        $message[] = $title;
        $message[] = '';
        $message[] = $description;
        $message[] = '';
        $message[] = '📦 <b>Позиции:</b>';

        foreach ($request->items as $item) {
            $icon = match ($item->status) {
                'approved' => '✅',
                'rejected' => '❌',
                default => '⏳',
            };

            $message[] = "{$icon} <b>" . e($item->item_name) . '</b> — ' . (int) $item->approved_qty . '/' . (int) $item->requested_qty;

            if ($item->admin_comment) {
                $message[] = '— <i>' . e($item->admin_comment) . '</i>';
            }
        }

        if ($request->admin_comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий администратора</b>';
            $message[] = e($request->admin_comment);
        }

        $applicationsUrl = config('services.inventory.applications_url');
        $adminChatUrl = config('services.inventory.admin_chat_url');

        $keyboardRow = [];

        if (filled($applicationsUrl)) {
            $keyboardRow[] = [
                'text' => '📋 Мои заявки',
                'url' => (string) $applicationsUrl,
            ];
        }

        if (filled($adminChatUrl)) {
            $keyboardRow[] = [
                'text' => '💬 Чат с админом',
                'url' => (string) $adminChatUrl,
            ];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => implode("\n", $message),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (! empty($keyboardRow)) {
            $payload['reply_markup'] = [
                'inline_keyboard' => [$keyboardRow],
            ];
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::error('Inventory result telegram send failed', [
                'request_id' => $request->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
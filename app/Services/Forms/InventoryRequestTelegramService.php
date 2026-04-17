<?php

namespace App\Services\Forms;

use App\Models\InventoryRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InventoryRequestTelegramService
{
    public function sendResult(InventoryRequest $request): void
    {
        $request->loadMissing(['user', 'lines']);

        $chatId = $request->user?->telegram_id;
        $token = config('services.telegram.bot_token');

        if (! $chatId || ! $token) {
            Log::warning('Inventory telegram notification skipped: missing credentials', [
                'request_id' => $request->id,
                'chat_id' => $chatId,
                'token_exists' => filled($token),
            ]);

            return;
        }

        $title = match ($request->status) {
            'issued' => '✅ <b>Заявка на инвентарь обработана</b>',
            'partially_issued' => '🟡 <b>Заявка на инвентарь обработана частично</b>',
            'cancelled' => '❌ <b>Заявка на инвентарь отклонена</b>',
            default => '📌 <b>Статус заявки обновлён</b>',
        };

        $description = match ($request->status) {
            'issued' => 'Все выбранные позиции были выданы.',
            'partially_issued' => 'Часть позиций выдали, а часть — нет или не полностью.',
            'cancelled' => 'К сожалению, позиции по заявке не были выданы.',
            default => 'Статус заявки был обновлён.',
        };

        $message = [];
        $message[] = $title;
        $message[] = '';
        $message[] = $description;
        $message[] = '';
        $message[] = '📦 <b>Позиции:</b>';

        foreach ($request->lines->sortBy('id') as $line) {
            $name = e($line->item_name);

            if ($line->variant_label) {
                $name .= ' <i>(' . e($line->variant_label) . ')</i>';
            }

            $lineStatus = match ($line->status) {
                'issued' => '✅',
                'partially_issued' => '🟡',
                'cancelled' => '❌',
                default => '⏳',
            };

            $message[] = "{$lineStatus} {$name}";
            $message[] = "— Запрошено: {$line->requested_qty}, выдано: {$line->issued_qty}";

            if ($line->admin_comment) {
                $message[] = '— <i>' . e($line->admin_comment) . '</i>';
            }
        }

        if ($request->admin_comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий администратора</b>';
            $message[] = e($request->admin_comment);
        }

        $applicationsUrl = config('services.inventory.applications_url');
        $adminChatUrl = config('services.inventory.admin_chat_url');

        $keyboardButtons = [];

        if (filled($applicationsUrl)) {
            $keyboardButtons[] = [
                'text' => '📋 Мои заявки',
                'url' => (string) $applicationsUrl,
            ];
        }

        if (filled($adminChatUrl)) {
            $keyboardButtons[] = [
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

        if (! empty($keyboardButtons)) {
            $payload['reply_markup'] = [
                'inline_keyboard' => [
                    $keyboardButtons,
                ],
            ];
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::error('Inventory telegram send failed', [
                'request_id' => $request->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
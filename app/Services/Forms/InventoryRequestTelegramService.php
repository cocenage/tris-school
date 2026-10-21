<?php

namespace App\Services\Forms;

use App\Models\InventoryRequest;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramRichMessageBuilder;
use Illuminate\Support\Facades\Log;

class InventoryRequestTelegramService
{
    public function syncSourceMessage(InventoryRequest $request): bool
    {
        if (! $request->telegram_source_chat_id || ! $request->telegram_source_message_id) {
            return false;
        }

        $request->load(['user', 'lines.processor']);
        $version = substr(hash('sha256', $request->lines->sortBy('id')
            ->map(fn ($line) => $line->id.':'.$line->status.':'.$line->issued_qty.':'.$line->processed_at?->toISOString())->implode('|')), 0, 16);
        $context = [
            'operation_key' => 'inventory:source-edit:'.$request->id.':'.$version,
            'notification_type' => 'inventory', 'method' => 'editMessageText', 'bot' => 'work',
            'chat_id' => (string) $request->telegram_source_chat_id,
            'source_type' => 'InventoryRequest', 'source_id' => $request->id,
        ];
        $monitor = app(TelegramOutboundMonitor::class);
        $monitor->expect($context);
        $status = match ($request->status) {
            'issued' => 'выдана', 'partially_issued' => 'частично выдана', 'cancelled' => 'отклонена',
            default => 'ожидает обработки',
        };
        $lines = [
            '📦 <b>Заявка на инвентарь #'.$request->id.'</b>',
            '👤 <b>Сотрудник:</b> '.e((string) ($request->user?->name ?: '—')),
            '<b>Статус:</b> '.$status,
            '',
            '📋 <b>Позиции:</b>',
        ];
        foreach ($request->lines->sortBy('id') as $item) {
            $label = match ($item->status) {
                'issued' => '✅ выдано', 'partially_issued' => '🟡 частично выдано',
                'cancelled' => '❌ отклонено', default => '⏳ ожидает',
            };
            $name = e((string) $item->item_name).(filled($item->variant_label) ? ' · '.e((string) $item->variant_label) : '');
            $line = '• '.$name.' — '.$label.' ('.(int) $item->issued_qty.'/'.(int) $item->requested_qty.')';
            if ($item->status !== 'pending') {
                $line .= ' · '.e((string) ($item->processor?->name ?: 'руководитель не указан'));
                if ($item->processed_at) {
                    $line .= ' · '.$item->processed_at->timezone('Europe/Rome')->format('d.m.Y H:i');
                }
            }
            $lines[] = $line;
        }
        if (filled($request->comment)) {
            $lines[] = '';
            $lines[] = '💬 <b>Комментарий сотрудника:</b> '.e((string) $request->comment);
        }
        if (filled($request->admin_comment)) {
            $lines[] = '💬 <b>Комментарий руководителя:</b> '.e((string) $request->admin_comment);
        }

        try {
            $edited = $monitor->withContext($context, fn () => app(TelegramBotService::class)->editMessage(
                (string) $request->telegram_source_chat_id, (int) $request->telegram_source_message_id, [],
                implode("\n", $lines), forceLegacy: true,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Inventory source message sync failed.', ['request_id' => $request->id, 'exception' => class_basename($exception)]);

            return false;
        }

        if (! $edited) {
            Log::warning('Inventory source message edit was rejected.', ['request_id' => $request->id]);
        }

        return $edited;
    }

    public function sendNewRequest(InventoryRequest $request): void
    {
        $request->loadMissing(['user', 'lines']);

        $token = config('services.telegram.bot_token');
        $forumChatId = config('services.staff_forms.chat_id');
        $threadId = config('services.staff_forms.inventory_thread_id');

        Log::info('Inventory new request telegram started', [
            'request_id' => $request->id,
            'forum_chat_id' => $forumChatId,
            'thread_id' => $threadId,
            'token_exists' => filled($token),
        ]);

        if (! $token || ! $forumChatId || ! $threadId) {
            Log::warning('Inventory new request telegram skipped: missing credentials', [
                'request_id' => $request->id,
                'token_exists' => filled($token),
                'forum_chat_id' => $forumChatId,
                'thread_id' => $threadId,
            ]);

            return;
        }

        $monitor = app(TelegramOutboundMonitor::class);
        $context = [
            'operation_key' => 'inventory:created:'.$request->id,
            'notification_type' => 'inventory', 'bot' => 'work',
            'chat_id' => (string) $forumChatId, 'message_thread_id' => $threadId,
            'source_type' => 'InventoryRequest', 'source_id' => $request->id,
        ];
        $monitor->expect($context);

        $message = [];
        $message[] = '📦 <b>Новая заявка на инвентарь</b>';
        $message[] = '';
        $message[] = '👤 <b>Сотрудник:</b> '.e($request->user?->name ?? '—');
        $message[] = '🆔 <b>Заявка:</b> #'.$request->id;
        $message[] = '';
        $message[] = '📋 <b>Позиции:</b>';

        foreach ($request->lines->sortBy('id') as $line) {
            $name = e($line->item_name);

            if ($line->variant_label) {
                $name .= ' <i>('.e($line->variant_label).')</i>';
            }

            $message[] = "— {$name}: {$line->requested_qty}";
        }

        if ($request->comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий:</b>';
            $message[] = e($request->comment);
        }

        try {
            $rich = app(TelegramRichMessageBuilder::class)->build(
                title: 'Новая заявка на инвентарь',
                status: 'Ожидает обработки',
                fields: [
                    'Сотрудник' => (string) ($request->user?->name ?? '—'),
                    'Заявка' => '#'.$request->id,
                    'Позиции' => $request->lines->sortBy('id')->map(fn ($line) => $line->item_name.': '.$line->requested_qty)->implode('; '),
                ],
                body: $request->comment,
            );

            $messageId = $monitor->withContext($context, fn () => app(TelegramBotService::class)->sendRichMessage(
                chatId: (string) $forumChatId,
                richMessage: $rich,
                fallbackHtml: implode("\n", $message),
                threadId: (string) $threadId,
            ));

            if (! $messageId) {
                throw new \RuntimeException('Telegram rejected inventory notification.');
            }
            $request->forceFill([
                'telegram_source_chat_id' => (string) $forumChatId,
                'telegram_source_message_id' => (int) $messageId,
            ])->saveQuietly();
        } catch (\Throwable $e) {
            Log::error('Inventory new request telegram exception', [
                'request_id' => $request->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function sendResult(InventoryRequest $request): void
    {
        $request->loadMissing(['user', 'lines']);

        $chatId = $request->user?->telegram_id;
        $token = config('services.telegram.bot_token');

        Log::info('Inventory telegram send started', [
            'request_id' => $request->id,
            'user_id' => $request->user?->id,
            'chat_id' => $chatId,
            'token_exists' => filled($token),
            'status' => $request->status,
        ]);

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
                $name .= ' <i>('.e($line->variant_label).')</i>';
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
                $message[] = '— <i>'.e($line->admin_comment).'</i>';
            }
        }

        if ($request->admin_comment) {
            $message[] = '';
            $message[] = '💬 <b>Комментарий администратора</b>';
            $message[] = e($request->admin_comment);
        }

        $keyboardButtons = [];

        $applicationsUrl = config('services.inventory.applications_url');
        $adminChatUrl = config('services.inventory.admin_chat_url');

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

        try {
            $rich = app(TelegramRichMessageBuilder::class)->build(
                title: 'Заявка на инвентарь',
                status: match ($request->status) {
                    'issued' => 'Обработана',
                    'partially_issued' => 'Обработана частично',
                    'cancelled' => 'Отклонена',
                    default => 'Статус обновлён',
                },
                fields: [
                    'Позиции' => $request->lines->sortBy('id')->map(fn ($line) => $line->item_name.': '.$line->requested_qty.' → '.$line->issued_qty)->implode('; '),
                ],
                body: $request->admin_comment,
            );

            $messageId = app(TelegramBotService::class)->sendRichMessage(
                chatId: (string) $chatId,
                richMessage: $rich,
                fallbackHtml: $payload['text'],
                replyMarkup: $payload['reply_markup'] ?? null,
            );

            if (! $messageId) {
                throw new \RuntimeException('Telegram rejected inventory result notification.');
            }
        } catch (\Throwable $e) {
            Log::error('Inventory telegram send exception', [
                'request_id' => $request->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

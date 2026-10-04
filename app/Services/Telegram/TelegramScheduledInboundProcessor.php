<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;

class TelegramScheduledInboundProcessor
{
    public function __construct(
        private readonly TelegramUpdateIngestService $ingest,
        private readonly TelegramScheduledControlResponseService $responses,
    ) {}

    /** @return array{ok: true, skipped?: string, message_id?: int|null} */
    public function process(array $update): array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (! $message || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return ['ok' => true, 'skipped' => 'no_group_message'];
        }

        $chatId = (string) data_get($message, 'chat.id');
        $allowed = config('services.telegram.scheduled_webhook_allowed_chat_ids', []);
        $permitted = $allowed !== []
            ? in_array($chatId, $allowed, true)
            : TelegramScheduledMessageDelivery::query()->where('chat_id', $chatId)->exists();

        if (! $permitted) {
            return ['ok' => true, 'skipped' => 'chat_not_allowed'];
        }

        $stored = $this->ingest->ingest($update);

        if ($stored) {
            $this->responses->capture($stored, $message);
        }

        return ['ok' => true, 'message_id' => $stored?->id];
    }
}

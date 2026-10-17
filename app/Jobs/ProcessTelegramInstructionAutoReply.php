<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramInstructionAutoReplyService;
use App\Services\Telegram\TelegramWorkActionFreshness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTelegramInstructionAutoReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(
        public int $telegramMessageId
    ) {}

    public function handle(TelegramInstructionAutoReplyService $autoReplyService): void
    {
        $message = TelegramMessage::query()
            ->with(['chat', 'topic', 'telegramUser', 'attachments'])
            ->find($this->telegramMessageId);

        if (! $message) {
            Log::warning('Telegram auto reply skipped: message not found', [
                'telegram_message_id' => $this->telegramMessageId,
            ]);

            return;
        }

        $update = $message->raw;
        $payload = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? null;

        if (! is_array($payload) || ! app(TelegramWorkActionFreshness::class)->messageIsCurrent($payload)) {
            app(TelegramWorkActionFreshness::class)->recordManualReconciliation(
                is_array($update) ? $update : [], 'stale_instruction_job',
            );

            return;
        }

        $autoReplyService->handle($message);
    }
}

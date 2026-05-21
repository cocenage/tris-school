<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramInstructionAutoReplyService;
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

        $autoReplyService->handle($message);
    }
}
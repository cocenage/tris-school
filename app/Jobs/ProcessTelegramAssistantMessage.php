<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramWorkActionFreshness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTelegramAssistantMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $telegramMessageId) {}

    public function handle(TelegramAssistantService $assistantService): void
    {
        $message = TelegramMessage::query()
            ->with(['chat', 'topic', 'telegramUser', 'attachments'])
            ->find($this->telegramMessageId);

        if (! $message) {
            Log::warning('Telegram assistant processing skipped: message not found', [
                'telegram_message_id' => $this->telegramMessageId,
            ]);

            return;
        }

        $update = $message->raw;
        $payload = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? null;

        if (! is_array($payload)) {
            Log::warning('Telegram assistant processing skipped: stored update has no message payload', [
                'telegram_message_id' => $this->telegramMessageId,
            ]);

            return;
        }

        if (! app(TelegramWorkActionFreshness::class)->messageIsCurrent($payload)) {
            app(TelegramWorkActionFreshness::class)->recordManualReconciliation($update, 'stale_assistant_job');

            return;
        }

        try {
            $assistantService->handle($message, $payload);
        } catch (Throwable $error) {
            Log::error('Telegram assistant processing failed after ingest', [
                'message_id' => $message->id,
                'exception' => class_basename($error),
            ]);

            throw $error;
        }
    }
}

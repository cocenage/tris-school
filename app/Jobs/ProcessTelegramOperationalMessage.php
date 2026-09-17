<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTelegramOperationalMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct(
        public int $telegramMessageId,
        public string $mode = 'message',
    ) {}

    public function handle(TelegramOperationalEventObserver $observer): void
    {
        $message = TelegramMessage::query()
            ->with(['chat', 'topic', 'telegramUser', 'attachments'])
            ->find($this->telegramMessageId);

        if (! $message) {
            Log::warning('Telegram operational observation skipped: message not found', [
                'telegram_message_id' => $this->telegramMessageId,
                'mode' => $this->mode,
            ]);

            return;
        }

        $result = $observer->observe($message, $this->mode);

        if ($this->mode === 'message' && filled($result['unanswered_due_at'] ?? null)) {
            self::dispatch($message->id, 'unanswered')
                ->delay(Carbon::parse($result['unanswered_due_at']));
        }
    }
}

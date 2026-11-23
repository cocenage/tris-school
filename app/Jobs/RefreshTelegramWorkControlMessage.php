<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\TelegramWorkControlMessageSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RefreshTelegramWorkControlMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(TelegramWorkControlMessageSync $sync): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->bot_type !== 'work'
            || $delivery->status !== 'sent' || ! $delivery->telegram_message_id) {
            return;
        }

        if (! $sync->sync($delivery)) {
            throw new RuntimeException('Telegram did not confirm the Work control edit.');
        }
    }
}

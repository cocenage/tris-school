<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;
use App\Services\Telegram\TelegramOksControlCards;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RefreshTelegramOksCard implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TelegramOksControlCards $cards): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->find($this->deliveryId);
        if ($delivery === null || ! isset(OksControlSchedule::LABELS[$delivery->control_type])
            || ($delivery->status !== 'sent' && ! $delivery->isOksTest()) || $delivery->telegram_message_id === null) {
            return;
        }

        if (! $cards->sync($delivery)) {
            throw new RuntimeException('Telegram did not confirm the OKS card edit.');
        }
    }
}

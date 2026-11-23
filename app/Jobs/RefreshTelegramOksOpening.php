<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramShiftOpening;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RefreshTelegramOksOpening implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(TelegramShiftOpening $opening, TelegramBotService $telegram): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->find($this->deliveryId);

        if ($delivery === null || $delivery->control_type !== ScheduledControlTypes::SHIFT_OPENING
            || ($delivery->status !== 'sent' && ! $delivery->isOksTest()) || ! $delivery->telegram_message_id) {
            return;
        }

        if (! $opening->syncQuick($delivery, $telegram)) {
            throw new RuntimeException('Telegram did not confirm the OKS opening edit.');
        }
    }
}

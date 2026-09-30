<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramScheduledMessageDeliveryService;
use Illuminate\Console\Command;

class TelegramScheduledMessagesSendCommand extends Command
{
    protected $signature = 'telegram:scheduled-messages-send';

    protected $description = 'Send configured Telegram scheduled messages that are due';

    public function handle(TelegramScheduledMessageDeliveryService $deliveries): int
    {
        $result = $deliveries->sendDue();

        $this->line(sprintf(
            'Due: %d; queued: %d; failed: %d; duplicate: %d',
            $result['due'],
            $result['queued'],
            $result['failed'],
            $result['duplicate'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

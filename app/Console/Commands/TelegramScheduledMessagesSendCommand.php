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
            'Due: %d; sent: %d; failed: %d; duplicate: %d; Telegram actions: %d',
            $result['due'],
            $result['sent'],
            $result['failed'],
            $result['duplicate'],
            $result['telegram_actions'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

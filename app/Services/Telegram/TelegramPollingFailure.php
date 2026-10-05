<?php

namespace App\Services\Telegram;

use RuntimeException;
use Throwable;

class TelegramPollingFailure extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly ?int $updateId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('telegram_polling_'.$stage.'_failure', 0, $previous);
    }
}

<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [10, 30];
    }

    public function __construct(
        public string $serviceClass,
        public string $method,
        public string $modelClass,
        public int $modelId,
    ) {}

    public function handle(): void
    {
        $record = ($this->modelClass)::query()->find($this->modelId);

        if (! $record) {
            Log::warning('Telegram notification job skipped: record not found', [
                'model' => $this->modelClass,
                'record_id' => $this->modelId,
            ]);

            return;
        }

        app($this->serviceClass)->{$this->method}($record);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Telegram notification job failed permanently', [
            'service' => $this->serviceClass,
            'model' => $this->modelClass,
            'record_id' => $this->modelId,
            'error' => $exception->getMessage(),
        ]);
    }
}

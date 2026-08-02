<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendRequestResultNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 3600;

    public function __construct(
        public string $serviceClass,
        public string $modelClass,
        public int $modelId,
        public bool $markNotified = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->serviceClass . ':' . $this->modelClass . ':' . $this->modelId;
    }

    public function handle(): void
    {
        $record = ($this->modelClass)::query()->find($this->modelId);

        if (! $record) {
            Log::warning('Telegram result job skipped: record not found', [
                'model' => $this->modelClass,
                'record_id' => $this->modelId,
            ]);

            return;
        }

        app($this->serviceClass)->sendResult($record);

        if ($this->markNotified && in_array('notified_at', $record->getFillable(), true)) {
            $record->forceFill(['notified_at' => now()])->saveQuietly();
        }
    }
}

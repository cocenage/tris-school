<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramOutboundMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendRequestResultNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 3600;

    public ?array $deliveryContext = null;

    public function backoff(): array
    {
        return [10, 30];
    }

    public function __construct(
        public string $serviceClass,
        public string $modelClass,
        public int $modelId,
        public bool $markNotified = false,
    ) {
        // The job is constructed before it is queued; this reservation survives a lost queue dispatch.
        try {
            $record = $modelClass::query()->with('user')->find($modelId);
            $this->deliveryContext = $record ? self::reserve($record) : null;
        } catch (Throwable) {
            // Delivery monitoring must not prevent a business notification from entering the queue.
            $this->deliveryContext = null;
        }
    }

    public static function reserve(Model $record): ?array
    {
        try {
            $record->loadMissing('user');
            $chatId = $record->user?->telegram_id;
            if (! $chatId || ! config('services.telegram.bot_token')) {
                return null;
            }

            $details = method_exists($record, 'days') ? $record->days()->pluck('status', 'id')->all()
                : (method_exists($record, 'lines') ? $record->lines()->pluck('status', 'id')->all() : []);
            $version = substr(hash('sha256', json_encode([$record->status, $record->updated_at?->toISOString(), $details])), 0, 16);
            $type = match (class_basename($record)) {
                'DayOffRequest' => 'day_off', 'VacationRequest' => 'vacation', 'InventoryRequest' => 'inventory',
                default => 'other',
            };
            $context = [
                'operation_key' => $type.':result:'.$record->getKey().':'.$version,
                'notification_type' => $type, 'bot' => 'work', 'chat_id' => (string) $chatId,
                'source_type' => class_basename($record), 'source_id' => $record->getKey(),
            ];
            app(TelegramOutboundMonitor::class)->expect($context);

            return $context;
        } catch (Throwable) {
            return null;
        }
    }

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

        if ($this->deliveryContext) {
            app(TelegramOutboundMonitor::class)->withContext(
                $this->deliveryContext,
                fn () => app($this->serviceClass)->sendResult($record),
            );
        } else {
            app($this->serviceClass)->sendResult($record);
        }

        if ($this->markNotified && in_array('notified_at', $record->getFillable(), true)) {
            $record->forceFill(['notified_at' => now()])->saveQuietly();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Telegram result notification job failed permanently', [
            'service' => $this->serviceClass,
            'model' => $this->modelClass,
            'record_id' => $this->modelId,
            'exception' => class_basename($exception),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\TelegramBotService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverScheduledTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public function __construct(
        public int $deliveryId,
        public CarbonImmutable $retryUntilAt,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 600, 600];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryUntilAt;
    }

    public function handle(TelegramBotService $telegram): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, ['sent', 'failed'], true)) {
            return;
        }

        $message = $delivery->scheduledMessage;

        if ($message === null) {
            $this->failDelivery($delivery, 'scheduled_message_unavailable');

            return;
        }

        if (! filled(config('services.telegram.scheduled_bot_token'))) {
            $this->failDelivery($delivery, 'scheduled_bot_token_missing');
            Log::warning('Scheduled Telegram bot token is not configured.', [
                'delivery_id' => $delivery->getKey(),
            ]);

            return;
        }

        if (! filled($delivery->chat_id)) {
            $this->failDelivery($delivery, 'destination_unavailable');

            return;
        }

        try {
            $messageId = $telegram->sendScheduledMessage(
                (string) $delivery->chat_id,
                htmlspecialchars((string) $message->message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $delivery->message_thread_id === null ? null : (string) $delivery->message_thread_id,
            );
        } catch (ConnectionException $exception) {
            $this->markRetrying($delivery, 'connection_exception');

            throw $exception;
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            $reason = 'telegram_http_'.$status;

            if ($this->isRetryableStatus($status)) {
                $this->markRetrying($delivery, $reason);

                throw $exception;
            }

            $this->failDelivery($delivery, $reason);
            Log::warning('Scheduled Telegram delivery failed with a permanent HTTP response.', [
                'delivery_id' => $delivery->getKey(),
                'status' => $status,
            ]);

            return;
        } catch (Throwable $exception) {
            $this->failDelivery($delivery, 'delivery_exception:'.class_basename($exception));
            Log::error('Scheduled Telegram delivery failed unexpectedly.', [
                'delivery_id' => $delivery->getKey(),
                'exception' => class_basename($exception),
            ]);

            return;
        }

        if ($messageId === null) {
            $this->failDelivery($delivery, 'telegram_response_missing_message_id');

            return;
        }

        $delivery->update([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::now(config('app.timezone', 'Europe/Rome')),
            'telegram_message_id' => $messageId,
            'failure_reason' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'sent') {
            return;
        }

        $reason = $delivery->failure_reason ?: match (true) {
            $exception instanceof ConnectionException => 'connection_exception',
            $exception instanceof RequestException => 'telegram_http_'.$exception->response->status(),
            default => 'retry_window_or_attempt_limit_exhausted:'.class_basename($exception),
        };

        $this->failDelivery($delivery, $reason);
        Log::error('Scheduled Telegram delivery exhausted its retry policy.', [
            'delivery_id' => $delivery->getKey(),
            'exception' => class_basename($exception),
        ]);
    }

    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private function markRetrying(TelegramScheduledMessageDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => 'retrying',
            'failure_reason' => $reason,
        ]);
    }

    private function failDelivery(TelegramScheduledMessageDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }
}

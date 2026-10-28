<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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

        if ($delivery === null || in_array($delivery->status, ['sent', 'failed', 'cancelled', 'uncertain'], true)) {
            return;
        }

        if ($delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS && $delivery->trigger_delivery_id !== null) {
            Cache::lock('scheduled_control_delivery:'.$delivery->trigger_delivery_id, 30)->block(5, function () use ($delivery, $telegram): void {
                $delivery = $delivery->fresh();
                if ($delivery === null || ! in_array($delivery->status, ['pending', 'retrying'], true)) {
                    return;
                }
                $source = TelegramScheduledMessageDelivery::query()->find($delivery->trigger_delivery_id);
                $message = $delivery->scheduledMessage;
                $now = CarbonImmutable::now('UTC')->setTimezone('Europe/Rome');
                $minimum = $now->startOfDay()->setTime(18, 0);
                $configured = $message ? $now->startOfDay()->setTimeFromTimeString((string) $message->send_time) : $minimum;
                if ($source === null || $source->status !== 'sent'
                    || $source->control_type !== ScheduledControlTypes::CLOSING_CLEANINGS
                    || $source->scheduledMessage === null || ! $source->scheduledMessage->enabled
                    || ! CarbonImmutable::parse($source->getRawOriginal('scheduled_for'), 'Europe/Rome')->isSameDay($now)
                    || $message === null || ! $message->enabled
                    || $now->lt($minimum) || $now->lt($configured)
                    || ! app(TelegramScheduledControlResponseService::class)->allDistrictsConfirmed($source)) {
                    $delivery->update(['status' => 'cancelled', 'failure_reason' => 'confirmation_not_current']);

                    return;
                }
                $this->deliver($delivery, $telegram);
            });

            return;
        }

        $this->deliver($delivery, $telegram);
    }

    private function deliver(TelegramScheduledMessageDelivery $delivery, TelegramBotService $telegram): void
    {
        $message = $delivery->scheduledMessage;

        if ($message === null) {
            $this->failDelivery($delivery, 'scheduled_message_unavailable');

            return;
        }

        $isWorkControl = $delivery->bot_type === 'work' && array_key_exists((string) $delivery->control_type, ScheduledControlTypes::LABELS);
        if (! filled(config($isWorkControl ? 'services.telegram.bot_token' : 'services.telegram.scheduled_bot_token'))) {
            $this->failDelivery($delivery, $isWorkControl ? 'work_bot_token_missing' : 'scheduled_bot_token_missing');
            Log::warning($isWorkControl ? 'Work Telegram bot token is not configured for a scheduled control.' : 'Scheduled Telegram bot token is not configured.', [
                'delivery_id' => $delivery->getKey(),
            ]);

            return;
        }

        if (! filled($delivery->chat_id)) {
            $this->failDelivery($delivery, 'destination_unavailable');

            return;
        }

        try {
            $send = $isWorkControl ? 'sendWorkControlMessage' : 'sendScheduledMessage';
            $text = $isWorkControl
                ? app(TelegramScheduledControlSummaryFormatter::class)->formatInteractive($delivery)
                : htmlspecialchars((string) $message->message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $messageId = app(TelegramOutboundMonitor::class)->withContext([
                'operation_key' => 'scheduled_delivery:'.$delivery->id,
                'notification_type' => 'scheduled_control',
                'source_type' => 'telegram_scheduled_message_delivery',
                'source_id' => $delivery->id,
                'scheduled_for' => $delivery->scheduled_for,
            ], fn () => $telegram->{$send}(
                (string) $delivery->chat_id,
                $text,
                $delivery->message_thread_id === null ? null : (string) $delivery->message_thread_id,
                $isWorkControl ? ScheduledControlTypes::districtKeyboard($delivery->id) : (ScheduledControlTypes::supportsInlineAnswer($delivery->control_type) ? [
                    'inline_keyboard' => [[
                        ['text' => '✅ Да', 'callback_data' => 'sc:'.$delivery->id.':yes'],
                        ['text' => '❌ Нет', 'callback_data' => 'sc:'.$delivery->id.':no'],
                    ]],
                ] : null),
            ));
        } catch (ConnectionException $exception) {
            if ($delivery->trigger_delivery_id !== null && $delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS) {
                $delivery->update(['status' => 'uncertain', 'failure_reason' => 'connection_exception']);

                return;
            }
            $this->markRetrying($delivery, 'connection_exception');

            throw $exception;
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            $reason = 'telegram_http_'.$status;

            if ($this->isRetryableStatus($status)) {
                if ($delivery->trigger_delivery_id !== null && $delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS
                    && ($status >= 500 || $status === 408)) {
                    $delivery->update(['status' => 'uncertain', 'failure_reason' => $reason]);

                    return;
                }
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
            if ($delivery->trigger_delivery_id !== null && $delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS) {
                $delivery->update(['status' => 'uncertain', 'failure_reason' => 'telegram_response_missing_message_id']);
            } else {
                $this->failDelivery($delivery, 'telegram_response_missing_message_id');
            }

            return;
        }

        $delivery->update([
            'status' => 'sent',
            'sent_at' => CarbonImmutable::now('UTC')->setTimezone(config('app.timezone', 'Europe/Rome')),
            'sent_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
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

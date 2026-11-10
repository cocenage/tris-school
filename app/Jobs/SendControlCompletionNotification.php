<?php

namespace App\Jobs;

use App\Models\ControlCompletionNotification;
use App\Models\ControlResponse;
use App\Models\User;
use App\Services\Controls\ControlCompletionNotificationService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendControlCompletionNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_DELIVERY_ATTEMPTS = 5;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $notificationId) {}

    public function uniqueId(): string
    {
        return 'control-completion-notification:'.$this->notificationId;
    }

    public function handle(
        TelegramBotService $telegram,
        TelegramOutboundMonitor $monitor,
        ControlCompletionNotificationService $links,
    ): void {
        $notification = $this->claim();
        if (! $notification) {
            return;
        }

        $response = ControlResponse::query()->with(['apartment', 'cleaner'])->find($notification->control_response_id);
        $recipient = $notification->recipient_user_id
            ? User::query()->find($notification->recipient_user_id)
            : null;

        if (! $response || ! $recipient || (int) $response->cleaner_id !== (int) $recipient->id
            || ! in_array($recipient->role, ['cleaner', 'supervisor'], true)
            || $recipient->status !== 'approved' || ! $recipient->is_active) {
            $this->finish('skipped', 'recipient_unavailable');

            return;
        }

        if (! filled(config('services.telegram.bot_token')) || ! filled($recipient->telegram_id)) {
            $this->retryLater('telegram_configuration_missing', 300);

            return;
        }

        $miniAppUrl = $links->miniAppUrl((int) $response->getKey());
        if (! $miniAppUrl) {
            $this->retryLater('telegram_bot_username_missing', 300);

            return;
        }

        $context = [
            'operation_key' => 'control:completion:'.$response->getKey(),
            'notification_type' => 'control_completion',
            'bot' => 'work',
            'chat_id' => (string) $recipient->telegram_id,
            'source_type' => 'ControlResponse',
            'source_id' => (string) $response->getKey(),
        ];

        try {
            $messageId = $monitor->withContext($context, fn (): ?int => $telegram->sendWorkControlMessage(
                chatId: (string) $recipient->telegram_id,
                text: $this->message($response),
                replyMarkup: [
                    'inline_keyboard' => [[[
                        'text' => 'Открыть контроль',
                        'url' => $miniAppUrl,
                    ]]],
                ],
            ));
        } catch (ConnectionException) {
            $this->finish('uncertain', 'connection_outcome_unknown');

            return;
        } catch (RequestException $exception) {
            $this->handleRequestFailure($exception);

            return;
        } catch (Throwable $exception) {
            Log::warning('Control completion notification failed before a receipt was confirmed.', [
                'notification_id' => $this->notificationId,
                'error' => class_basename($exception),
            ]);
            $this->finish('uncertain', 'delivery_outcome_unknown');

            return;
        }

        if (! is_int($messageId)) {
            $this->finish('failed', 'telegram_rejected_without_receipt');

            return;
        }

        $this->finish('sent', null, $messageId);
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish('uncertain', 'worker_failed');
    }

    private function claim(): ?ControlCompletionNotification
    {
        return DB::transaction(function (): ?ControlCompletionNotification {
            $notification = ControlCompletionNotification::query()
                ->whereKey($this->notificationId)
                ->lockForUpdate()
                ->first();

            if (! $notification || $notification->status !== 'pending'
                || ($notification->next_attempt_at && $notification->next_attempt_at->isFuture())) {
                return null;
            }

            $notification->forceFill([
                'status' => 'sending',
                'attempts' => (int) $notification->attempts + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ])->saveQuietly();

            return $notification;
        });
    }

    private function handleRequestFailure(RequestException $exception): void
    {
        $status = $exception->response?->status();

        if ($status === 429) {
            $retryAfter = max(1, min(3600, (int) data_get($exception->response?->json(), 'parameters.retry_after', 60)));
            $this->retryLater('telegram_rate_limited', $retryAfter);

            return;
        }

        $this->finish($status !== null && $status >= 500 ? 'uncertain' : 'failed',
            $status !== null && $status >= 500 ? 'telegram_outcome_unknown' : 'telegram_rejected');
    }

    private function retryLater(string $reason, int $seconds): void
    {
        $notification = ControlCompletionNotification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        $canRetry = (int) $notification->attempts < self::MAX_DELIVERY_ATTEMPTS;

        $notification->forceFill([
            'status' => $canRetry ? 'pending' : 'failed',
            'last_error' => $reason,
            'next_attempt_at' => $canRetry ? now()->addSeconds($seconds) : null,
        ])->saveQuietly();
    }

    private function finish(string $status, ?string $reason, ?int $messageId = null): void
    {
        try {
            ControlCompletionNotification::query()->whereKey($this->notificationId)->update([
                'status' => $status,
                'last_error' => $reason,
                'telegram_message_id' => $messageId,
                'sent_at' => $status === 'sent' ? now() : null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            Log::warning('Control completion notification status could not be recorded.', [
                'notification_id' => $this->notificationId,
                'error' => class_basename($exception),
            ]);
        }
    }

    private function message(ControlResponse $response): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $apartment = $response->apartment?->name ?: 'Квартира не указана';
        $date = $response->inspection_date?->format('d.m.Y') ?: '—';
        $zone = $response->result_zone_label;
        $reason = trim((string) $response->result_zone_reason);
        $reason = $reason === '' ? '' : "\n".$escape(mb_substr($reason, 0, 400));
        $critical = $response->has_critical_failure ? 'Да' : 'Нет';

        return implode("\n", [
            '✅ <b>Контроль качества завершён</b>',
            '',
            '<b>Квартира:</b> '.$escape($apartment),
            '<b>Дата:</b> '.$escape($date),
            '<b>Результат:</b> '.$escape($zone).' · '.(int) $response->score_percent.'%',
            '<b>Ошибки:</b> '.(int) $response->errors_count.' · <b>Штраф:</b> '.(int) $response->penalty_points,
            '<b>Критическая ошибка:</b> '.$critical,
            $reason,
        ]);
    }
}

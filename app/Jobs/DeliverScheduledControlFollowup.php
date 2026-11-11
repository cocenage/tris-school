<?php

namespace App\Jobs;

use App\Models\TelegramScheduledControlFollowup;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramScheduledControlFollowupService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverScheduledControlFollowup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $followupId) {}

    public function handle(TelegramBotService $telegram, TelegramScheduledControlFollowupService $followups): void
    {
        $followup = TelegramScheduledControlFollowup::query()->with('delivery')->find($this->followupId);
        if ($followup === null || $followup->status !== 'queued' || $followup->delivery === null) {
            return;
        }

        $lockKey = 'scheduled_control_delivery:'.$followup->delivery_id;
        try {
            Cache::lock($lockKey, 30)->block(5, function () use ($followup, $telegram, $followups): void {
                $claimed = TelegramScheduledControlFollowup::query()->whereKey($followup->id)->where('status', 'queued')
                    ->update(['status' => 'sending']);
                if ($claimed !== 1) {
                    return;
                }

                if (! $followups->isStillDue($followup, CarbonImmutable::now('UTC')->setTimezone('Europe/Rome'))) {
                    $followup->update(['status' => 'cancelled', 'failure_reason' => 'no_longer_due']);

                    return;
                }

                $delivery = $followup->delivery;
                if (! filled(config('services.telegram.bot_token')) || ! $delivery->telegram_message_id) {
                    $followup->update(['status' => 'failed', 'failure_reason' => 'destination_or_bot_unavailable']);

                    return;
                }

                $district = htmlspecialchars(ScheduledControlTypes::DISTRICTS[$followup->district_key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = htmlspecialchars(ScheduledControlTypes::LABELS[$delivery->control_type] ?? $delivery->control_type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $heading = $followup->kind === 'reminder' ? '⏰ НАПОМИНАНИЕ' : '⏳ ПОВТОРНЫЙ КОНТРОЛЬ';
                $text = $heading."\n\n".$district.' — '.$label.".\n\nКакая ситуация сейчас?";
                if (str_starts_with((string) $delivery->chat_id, '-100')) {
                    $text .= "\n\n".'<a href="https://t.me/c/'.substr((string) $delivery->chat_id, 4).'/'.$delivery->telegram_message_id.'">Исходный контроль</a>';
                }
                [$yesLabel, $noLabel] = match ($delivery->control_type) {
                    'schedule_checked' => ['✅ Проверены', '❌ Ещё не проверены'],
                    'first_cleanings_started' => ['✅ Начались', '❌ Не все начались'],
                    'couriers_completed' => ['✅ Доставлены', '❌ Ещё не доставлены'],
                    ScheduledControlTypes::CLOSING_PAYMENTS => ['✅ Произведены', '❌ Ещё не произведены'],
                    default => ['✅ Завершены', '❌ Ещё продолжаются'],
                };
                $buttons = ['inline_keyboard' => [[
                    ['text' => $yesLabel, 'callback_data' => 'scf:'.$followup->id.':yes'],
                    ['text' => $noLabel, 'callback_data' => 'scf:'.$followup->id.':no'],
                ]]];

                try {
                    $messageId = app(TelegramOutboundMonitor::class)->withContext([
                        'operation_key' => 'scheduled_control_followup:'.$followup->id,
                        'notification_type' => 'scheduled_control_followup',
                        'source_type' => 'scheduled_control_followup',
                        'source_id' => $followup->id,
                    ], fn () => $telegram->sendWorkControlMessage(
                        (string) $delivery->chat_id, $text,
                        $delivery->message_thread_id === null ? null : (string) $delivery->message_thread_id,
                        $buttons,
                    ));
                    if ($messageId === null) {
                        $followup->update(['status' => 'uncertain', 'failure_reason' => 'missing_receipt']);

                        return;
                    }
                    $followup->update([
                        'status' => 'sent', 'sent_at' => CarbonImmutable::now('UTC')->setTimezone('Europe/Rome'),
                        'sent_at_utc' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                        'telegram_message_id' => $messageId, 'failure_reason' => null,
                    ]);
                } catch (ConnectionException $exception) {
                    $followup->update(['status' => 'uncertain', 'failure_reason' => 'connection_exception']);
                    Log::warning('Scheduled control follow-up result is uncertain.', ['followup_id' => $followup->id]);
                } catch (RequestException $exception) {
                    $status = $exception->response->status();
                    $followup->update([
                        'status' => $status >= 500 || $status === 408 ? 'uncertain' : 'failed',
                        'failure_reason' => 'telegram_http_'.$status,
                    ]);
                } catch (Throwable $exception) {
                    $followup->update(['status' => 'uncertain', 'failure_reason' => 'unexpected_exception']);
                    Log::warning('Scheduled control follow-up result is uncertain.', [
                        'followup_id' => $followup->id, 'exception' => class_basename($exception),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Scheduled control follow-up lock failed.', [
                'followup_id' => $followup->id, 'exception' => class_basename($exception),
            ]);
            TelegramScheduledControlFollowup::query()->whereKey($followup->id)->where('status', 'queued')
                ->update(['failure_reason' => 'lock_unavailable']);
        }
    }
}

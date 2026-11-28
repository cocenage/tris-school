<?php

namespace App\Jobs;

use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramScheduledMessageDeliveryService;
use App\Services\Telegram\TelegramShiftOpening;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The recovery job itself is durable before the original delivery is reopened.
 * This also works when the queue uses a separate SQLite database.
 */
class RecoverOksDelivery extends DeliverScheduledTelegramMessage implements ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public string $recoveryToken;

    public function __construct(
        int $deliveryId,
        CarbonImmutable $retryUntilAt,
        public ?string $confirmedAbsentRevision = null,
    ) {
        parent::__construct($deliveryId, $retryUntilAt);
        $this->recoveryToken = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public static function isCurrentDestination(TelegramScheduledMessageDelivery $delivery, CarbonImmutable $now): bool
    {
        $anchor = app(TelegramScheduledMessageDeliveryService::class)->currentOksAnchor($now);
        $slot = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');

        return $anchor !== null
            && (int) $delivery->scheduled_message_id === (int) $anchor->id
            && $delivery->bot_type === 'work'
            && (string) $delivery->chat_id === (string) $anchor->telegramChat?->telegram_chat_id
            && (string) $delivery->message_thread_id === (string) $anchor->telegramTopic?->telegram_thread_id
            && $slot->isSameDay($now->setTimezone('Europe/Rome'))
            && $slot->lte($now)
            && ($delivery->control_type === ScheduledControlTypes::SHIFT_OPENING
                || isset(OksControlSchedule::LABELS[$delivery->control_type]));
    }

    public function handle(TelegramBotService $telegram): void
    {
        $ready = DB::transaction(function (): bool {
            $delivery = TelegramScheduledMessageDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();
            $now = CarbonImmutable::now('Europe/Rome');
            if ($delivery === null || ! self::isCurrentDestination($delivery, $now)
                || $delivery->telegram_message_id !== null || $delivery->sent_at !== null) {
                return false;
            }

            $context = $delivery->sequence_context ?? [];
            if (($context['oks_recovery_token'] ?? null) === $this->recoveryToken) {
                // Only this recovery attempt may resume after a worker interruption.
                // In particular, never retry an ambiguous Telegram response.
                return in_array($delivery->status, ['pending', 'retrying'], true);
            }

            if ($this->confirmedAbsentRevision !== null) {
                if ($delivery->status !== 'uncertain'
                    || (string) $delivery->getRawOriginal('updated_at') !== $this->confirmedAbsentRevision) {
                    return false;
                }
            } elseif ($delivery->status !== 'skipped'
                || ! isset(OksControlSchedule::LABELS[$delivery->control_type])
                || ! in_array($delivery->failure_reason, ['oks_opening_unavailable', 'oks_no_active_districts'], true)) {
                return false;
            }

            if ($delivery->control_type !== ScheduledControlTypes::SHIFT_OPENING
                && empty(app(TelegramShiftOpening::class)->assignmentsFor($delivery))) {
                return false;
            }

            $delivery->update([
                'status' => 'pending',
                'failure_reason' => null,
                'sequence_context' => [...$context, 'oks_recovery_token' => $this->recoveryToken],
            ]);

            return true;
        });

        if ($ready) {
            Log::info('OKS delivery recovery started.', ['delivery_id' => $this->deliveryId]);
            parent::handle($telegram);
        }
    }

    public function failed(Throwable $exception): void
    {
        $delivery = TelegramScheduledMessageDelivery::query()->find($this->deliveryId);
        if ($delivery !== null
            && ($delivery->sequence_context['oks_recovery_token'] ?? null) === $this->recoveryToken
            && in_array($delivery->status, ['pending', 'retrying'], true)) {
            parent::failed($exception);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TelegramScheduledControlResponsesBackfillCommand extends Command
{
    protected $signature = 'telegram:scheduled-control-responses-backfill {--from=} {--to=} {--dry-run} {--apply}';

    protected $description = 'Backfill scheduled control replies from stored Telegram messages';

    public function handle(TelegramScheduledControlResponseService $responses): int
    {
        try {
            [$from, $to] = $this->dateRange();
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $timezone = 'Europe/Rome';
        $deliveries = TelegramScheduledMessageDelivery::query()
            ->where('status', 'sent')
            ->whereNotNull('sent_at')
            ->whereIn('control_type', array_keys(ScheduledControlTypes::LABELS))
            ->where('scheduled_for', '>=', $from->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $to->addDay()->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->orderBy('scheduled_for')->orderBy('id')->get();

        $targetDeliveryIds = $deliveries->modelKeys();
        $perDeliveryCounts = $deliveries->mapWithKeys(fn ($delivery): array => [$delivery->id => 0])->all();
        $candidates = [];

        foreach ($deliveries as $delivery) {
            if (! filled($delivery->chat_id) || ! is_numeric($delivery->telegram_message_id) || ! $delivery->sent_at) {
                continue;
            }

            TelegramMessage::query()
                ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', (string) $delivery->chat_id))
                ->where('sent_at', '>=', $delivery->sent_at)
                ->whereNotNull('raw')
                ->orderBy('sent_at')->orderBy('id')->get()
                ->each(function (TelegramMessage $stored) use ($delivery, &$candidates, &$perDeliveryCounts): void {
                    $message = $this->messagePayload($stored);
                    $replyId = data_get($message, 'reply_to_message.message_id');

                    if (! is_numeric($replyId) || (string) $replyId !== (string) $delivery->telegram_message_id) {
                        return;
                    }

                    $perDeliveryCounts[$delivery->id]++;
                    $candidates[$stored->id] ??= ['stored' => $stored, 'message' => $message];
                });
        }

        $alreadyCaptured = 0;
        $wouldInsert = 0;
        $inserted = 0;
        $unmatched = 0;
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('apply');

        foreach ($candidates as ['stored' => $stored, 'message' => $message]) {
            $match = $responses->resolveDelivery($message);
            $delivery = $match['delivery'];

            if ($match['status'] !== 'matched' || ! in_array($delivery->id, $targetDeliveryIds, true)) {
                $unmatched++;

                continue;
            }

            $chatId = (string) data_get($message, 'chat.id');
            $messageId = (string) ($message['message_id'] ?? '');
            $existing = TelegramScheduledMessageResponse::query()
                ->where('chat_id', $chatId)->where('telegram_message_id', $messageId)->exists();

            if ($existing) {
                $alreadyCaptured++;

                continue;
            }

            $wouldInsert++;

            if (! $dryRun && $responses->capture($stored, $message) !== null) {
                $inserted++;
            } elseif (! $dryRun) {
                $unmatched++;
            }
        }

        $this->line('Deliveries scanned: '.$deliveries->count());
        $this->line('Candidate replies found: '.count($candidates));
        $this->line('Already captured: '.$alreadyCaptured);
        $this->line('Would insert: '.$wouldInsert);
        $this->line('Unmatched/ambiguous: '.$unmatched);
        if (! $dryRun) {
            $this->line('Inserted: '.$inserted);
        }
        $this->line('Per-delivery candidate count:');

        foreach ($deliveries as $delivery) {
            $this->line(sprintf('  #%d (%s, message %s): %d', $delivery->id, $delivery->chat_id, $delivery->telegram_message_id, $perDeliveryCounts[$delivery->id]));
        }

        return self::SUCCESS;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dateRange(): array
    {
        $from = $this->date((string) $this->option('from'));
        $to = $this->date((string) $this->option('to'));

        if ($to->lt($from)) {
            throw new InvalidArgumentException('The --to date must be on or after --from.');
        }

        return [$from, $to];
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Rome');
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Both --from and --to must be valid dates in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function messagePayload(TelegramMessage $stored): array
    {
        $raw = $stored->raw;
        if (! is_array($raw)) {
            return [];
        }

        $message = $raw['message'] ?? $raw['edited_message'] ?? $raw['channel_post'] ?? [];

        return is_array($message) ? $message : [];
    }
}

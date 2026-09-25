<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApartmentShiftHandoffBuilder;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramApartmentHandoffSendCommand extends Command
{
    private const DELIVERY_GUARD_DAYS = 30;

    protected $signature = 'telegram:apartment-handoff-send
        {--date= : Calendar date in the application timezone}
        {--dry-run : Show destinations and final messages without calling Telegram}
        {--json : Emit machine-readable per-apartment results}';

    protected $description = 'Send one idempotent next-shift handoff to each mapped apartment topic';

    public function handle(TelegramApartmentShiftHandoffBuilder $builder, TelegramBotService $bot): int
    {
        $date = $this->dateOption();
        if ($date === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! (bool) config('services.telegram.evening_intelligence_delivery_enabled', false)) {
            $this->error('OI delivery is disabled. Use --dry-run for preview.');

            return self::FAILURE;
        }

        try {
            $preview = $builder->build($date);
        } catch (Throwable $exception) {
            Log::warning('Apartment handoff build failed.', ['exception' => $exception::class]);
            $this->error('Operational event ledger or apartment-topic mappings are unavailable.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($preview['skipped'] as $skipped) {
            Log::warning('Apartment handoff skipped: event has no mapped apartment.', [
                'event_key' => $skipped['event_key'],
            ]);
            $results[] = [
                'apartment' => null,
                'status' => 'skipped_missing_apartment',
                'items' => 1,
                'telegram_actions' => 0,
            ];
        }

        foreach ($preview['handoffs'] as $handoff) {
            if ($handoff['mapping_status'] !== 'ready') {
                Log::warning('Apartment handoff skipped: Telegram topic mapping unavailable.', [
                    'apartment_id' => $handoff['apartment_id'],
                    'reason' => $handoff['mapping_status'],
                ]);
                $results[] = $this->result($handoff, 'skipped_unmapped', 0);

                continue;
            }

            if ($dryRun) {
                $results[] = $this->result($handoff, 'previewed', 0);

                if (! $this->option('json')) {
                    $this->newLine();
                    $this->line($handoff['apartment_name'].' → '.$handoff['destination_topic_title']);
                    foreach (explode("\n", $handoff['message']) as $line) {
                        $this->line($line);
                    }
                }

                continue;
            }

            $deliveryKey = 'telegram_apartment_shift_handoff_v1:'.hash('sha256', implode('|', [
                $date->toDateString(),
                'shift_handoff',
                $handoff['apartment_id'],
                $handoff['destination_chat_id'],
                $handoff['destination_thread_id'],
            ]));

            try {
                $reserved = Cache::add($deliveryKey, 'attempted', now()->addDays(self::DELIVERY_GUARD_DAYS));
                $previous = $reserved ? null : Cache::get($deliveryKey);
            } catch (Throwable $exception) {
                Log::warning('Apartment handoff idempotency store failed closed.', [
                    'apartment_id' => $handoff['apartment_id'],
                    'exception' => $exception::class,
                ]);
                $results[] = $this->result($handoff, 'failed', 0, 'idempotency_store_unavailable');

                continue;
            }

            if (! $reserved) {
                $results[] = $this->result(
                    $handoff,
                    $previous === 'sent' ? 'skipped_duplicate' : 'blocked_uncertain',
                    0,
                );

                continue;
            }

            try {
                // TelegramBotService uses the existing AcademyBot and forum thread transport.
                $messageId = $bot->sendMessage(
                    (string) $handoff['destination_chat_id'],
                    htmlspecialchars($handoff['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    (string) $handoff['destination_thread_id'],
                );
            } catch (Throwable $exception) {
                $messageId = null;
                Log::warning('Apartment handoff Telegram send failed.', [
                    'apartment_id' => $handoff['apartment_id'],
                    'chat_id' => $handoff['destination_chat_id'],
                    'thread_id' => $handoff['destination_thread_id'],
                    'exception' => $exception::class,
                ]);
            }

            if ($messageId === null) {
                Log::warning('Apartment handoff Telegram API did not confirm delivery.', [
                    'apartment_id' => $handoff['apartment_id'],
                    'chat_id' => $handoff['destination_chat_id'],
                    'thread_id' => $handoff['destination_thread_id'],
                ]);
            }

            if ($messageId !== null) {
                try {
                    Cache::put($deliveryKey, 'sent', now()->addDays(self::DELIVERY_GUARD_DAYS));
                } catch (Throwable) {
                    // Leave the reservation as attempted; never retry an uncertain send automatically.
                }
            }

            $results[] = $this->result(
                $handoff,
                $messageId === null ? 'failed' : 'sent',
                $messageId === null ? 0 : 1,
                $messageId === null ? 'telegram_send_failed' : null,
            );
        }

        $payload = [
            'date' => $date->toDateString(),
            'dry_run' => $dryRun,
            'telegram_actions' => collect($results)->sum('telegram_actions'),
            'results' => $results,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($results === []) {
            $this->line('Нет активных событий, требующих внимания. Telegram actions: 0.');
        } else {
            foreach ($results as $result) {
                $this->line(sprintf(
                    '%s: %s (items: %d, Telegram actions: %d)',
                    $result['apartment'],
                    $result['status'],
                    $result['items'],
                    $result['telegram_actions'],
                ));
            }
        }

        return collect($results)->contains(fn (array $result): bool => in_array(
            $result['status'],
            ['failed', 'blocked_uncertain'],
            true,
        )) ? self::FAILURE : self::SUCCESS;
    }

    private function dateOption(): ?Carbon
    {
        if (! filled($this->option('date'))) {
            $this->error('Date is required and must use YYYY-MM-DD.');

            return null;
        }

        $value = (string) $this->option('date');
        $timezone = config('app.timezone', 'Europe/Rome');

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            $this->error('Date must use YYYY-MM-DD.');

            return null;
        }

        if ($date->format('Y-m-d') !== $value) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return null;
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function result(array $handoff, string $status, int $telegramActions, ?string $reason = null): array
    {
        return array_filter([
            'apartment' => $handoff['apartment_name'],
            'apartment_id' => $handoff['apartment_id'],
            'destination_chat_id' => $handoff['destination_chat_id'],
            'destination_thread_id' => $handoff['destination_thread_id'],
            'status' => $status,
            'items' => $handoff['item_count'],
            'telegram_actions' => $telegramActions,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);
    }
}

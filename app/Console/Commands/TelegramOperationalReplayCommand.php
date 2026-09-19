<?php

namespace App\Console\Commands;

use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TelegramOperationalReplayCommand extends Command
{
    protected $signature = 'telegram:operational-replay
        {--date= : One past calendar date in the application timezone}
        {--from= : Inclusive start date; requires --to}
        {--to= : Inclusive end date; requires --from}
        {--through-now : Catch up the current date only, stopping at the command-start clock}
        {--json : Emit machine-readable result}';

    protected $description = 'Replay a bounded period of stored work Telegram messages into the operational event ledger';

    public function handle(TelegramOperationalEventObserver $observer): int
    {
        $capturedNow = now(config('app.timezone', 'Europe/Rome'));
        $boundaries = $this->boundaries($capturedNow);

        if ($boundaries === null) {
            return self::FAILURE;
        }

        [$from, $to, $cutoff] = $boundaries;
        $result = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => config('app.timezone', 'Europe/Rome'),
            'examined' => 0,
            'no_event' => 0,
            'created' => 0,
            'updated' => 0,
            'evidence' => 0,
            'resolved' => 0,
            'reopened' => 0,
            'dismissed' => 0,
            'pending' => 0,
            'failed' => 0,
            'idempotent_reused' => 0,
            'event_keys' => [],
            'failure_message_ids' => [],
            'telegram_actions' => 0,
        ];

        if ($this->option('through-now')) {
            $result['cutoff_at'] = $cutoff->toIso8601String();
        }

        $pending = [];
        $bucket = [];
        $bucketTimestamp = null;

        foreach ($this->messages($from, $cutoff) as $message) {
            $timestamp = $message->sent_at?->toIso8601String() ?? $message->created_at?->toIso8601String();

            if ($bucket !== [] && $timestamp !== $bucketTimestamp) {
                $this->processBucket($bucket, $bucketTimestamp, $observer, $pending, $result);
                $bucket = [];
            }

            $bucketTimestamp = $timestamp;
            $bucket[] = $message;
        }

        if ($bucket !== []) {
            $this->processBucket($bucket, $bucketTimestamp, $observer, $pending, $result);
        }

        $this->maturePending($pending, $cutoff, $observer, $result);
        $result['event_keys'] = array_values(array_unique($result['event_keys']));
        $result['failure_message_ids'] = array_values(array_unique($result['failure_message_ids']));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('Operational replay: %s — %s', $result['from'], $result['to']));
            foreach (['examined', 'no_event', 'created', 'updated', 'evidence', 'resolved', 'reopened', 'dismissed', 'pending', 'failed'] as $key) {
                $this->line($key.': '.$result[$key]);
            }
            $this->line('Events: '.implode(', ', $result['event_keys']));
            $this->line('Telegram actions: 0');
        }

        return $this->option('through-now') && $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function boundaries(Carbon $capturedNow): ?array
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $date = $this->option('date');
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        $hasDate = filled($date);
        $hasRange = filled($fromOption) || filled($toOption);

        if ($this->option('through-now')) {
            if ($hasRange || ($hasDate && $date !== $capturedNow->toDateString())) {
                $this->error('--through-now accepts only the current --date and no range.');

                return null;
            }

            return [$capturedNow->copy()->startOfDay(), $capturedNow->copy()->startOfDay(), $capturedNow];
        }

        if (($hasDate && $hasRange) || (! $hasDate && ! $hasRange) || ($hasRange && (! filled($fromOption) || ! filled($toOption)))) {
            $this->error('Provide exactly --date or both --from and --to.');

            return null;
        }

        try {
            $from = Carbon::createFromFormat('!Y-m-d', (string) ($hasDate ? $date : $fromOption), $timezone)->startOfDay();
            $to = Carbon::createFromFormat('!Y-m-d', (string) ($hasDate ? $date : $toOption), $timezone)->startOfDay();
        } catch (Throwable) {
            $this->error('Replay boundaries must use YYYY-MM-DD.');

            return null;
        }

        if ($from->format('Y-m-d') !== (string) ($hasDate ? $date : $fromOption)
            || $to->format('Y-m-d') !== (string) ($hasDate ? $date : $toOption)) {
            $this->error('Replay boundaries must be valid YYYY-MM-DD dates.');

            return null;
        }

        if ($from->gt($to)) {
            $this->error('Replay start must be on or before replay end.');

            return null;
        }

        if ($from->diffInDays($to) > 6) {
            $this->error('Replay may span at most seven consecutive calendar dates.');

            return null;
        }

        if ($to->gte($capturedNow->copy()->startOfDay())) {
            $this->error('Replay boundaries must be in the past.');

            return null;
        }

        return [$from, $to, $to->copy()->endOfDay()];
    }

    private function messages(Carbon $from, Carbon $cutoff): iterable
    {
        $allowedChatIds = array_map('strval', config('services.telegram.operational_chat_ids', []));
        $query = TelegramMessage::query()
            ->with(['chat', 'topic', 'telegramUser', 'attachments'])
            ->whereBetween('sent_at', [$from->copy()->startOfDay(), $cutoff])
            ->whereHas('chat', function ($query) use ($allowedChatIds) {
                $query->whereIn('type', ['group', 'supergroup', 'channel']);

                if ($allowedChatIds !== []) {
                    $query->whereIn('telegram_chat_id', $allowedChatIds);
                }
            })
            ->orderBy('sent_at')
            ->orderBy('id');

        return $query->lazy(200);
    }

    private function processBucket(
        array $messages,
        ?string $timestamp,
        TelegramOperationalEventObserver $observer,
        array &$pending,
        array &$result,
    ): void {
        $clock = Carbon::parse($timestamp ?: now(), config('app.timezone', 'Europe/Rome'));

        foreach ($messages as $message) {
            $result['examined']++;

            try {
                $observation = $observer->observe($message, 'message', $clock);
                $this->count($observation, $result);

                if ($observation['unanswered_due_at']) {
                    $pending[$message->id] = Carbon::parse($observation['unanswered_due_at']);
                }
            } catch (Throwable) {
                $result['failed']++;
                $result['failure_message_ids'][] = $message->id;
            }
        }

        $this->maturePending($pending, $clock, $observer, $result);
    }

    private function maturePending(
        array &$pending,
        Carbon $clock,
        TelegramOperationalEventObserver $observer,
        array &$result,
    ): void {
        foreach ($pending as $messageId => $dueAt) {
            if ($dueAt->gt($clock)) {
                continue;
            }

            $message = TelegramMessage::query()
                ->with(['chat', 'topic', 'telegramUser', 'attachments'])
                ->find($messageId);

            if (! $message) {
                $result['failed']++;
                $result['failure_message_ids'][] = $messageId;
                unset($pending[$messageId]);

                continue;
            }

            try {
                $observation = $observer->observe($message, 'unanswered', $clock);
                $this->count($observation, $result);
            } catch (Throwable) {
                $result['failed']++;
                $result['failure_message_ids'][] = $messageId;
            }

            unset($pending[$messageId]);
        }
    }

    private function count(array $observation, array &$result): void
    {
        $outcome = $observation['outcome'];

        if ($outcome === 'pending_question') {
            $result['pending']++;
        } elseif (array_key_exists($outcome, $result)) {
            $result[$outcome]++;
        }

        if ($observation['idempotent_reuse']) {
            $result['idempotent_reused']++;
        }

        if ($observation['event_key']) {
            $result['event_keys'][] = $observation['event_key'];
        }
    }
}

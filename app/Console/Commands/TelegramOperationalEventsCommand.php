<?php

namespace App\Console\Commands;

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalObservation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TelegramOperationalEventsCommand extends Command
{
    protected $signature = 'telegram:operational-events
        {--event= : Exact stable event key}
        {--message= : Local Telegram message id}
        {--date= : Events or observations touching one calendar date}
        {--status= : Filter by current event status}
        {--json : Emit machine-readable result}';

    protected $description = 'Inspect the operational event ledger without raw Telegram payloads or writes';

    public function handle(): int
    {
        $date = $this->dateOption();

        if ($this->option('date') && ! $date) {
            return self::FAILURE;
        }

        $eventQuery = TelegramOperationalEvent::query()
            ->with([
                'chat:id,telegram_chat_id,title',
                'topic:id,telegram_thread_id,title',
                'evidence' => fn ($query) => $query->with([
                    'observation.message.chat:id,telegram_chat_id',
                    'observation.message.topic:id,telegram_thread_id',
                ])->orderBy('occurred_at')->orderBy('id'),
            ])
            ->latest('last_observed_at');

        if (filled($this->option('event'))) {
            $eventQuery->where('event_key', (string) $this->option('event'));
        }

        if (filled($this->option('status'))) {
            $eventQuery->where('status', (string) $this->option('status'));
        }

        if (filled($this->option('message'))) {
            $messageId = (int) $this->option('message');
            $eventQuery->whereHas('evidence.observation', fn ($query) => $query->where('telegram_message_id', $messageId));
        }

        if ($date) {
            $eventQuery->whereHas('evidence', fn ($query) => $query->whereBetween('occurred_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]));
        }

        $events = $eventQuery->limit(50)->get()->map(fn (TelegramOperationalEvent $event) => [
            'event_key' => $event->event_key,
            'types' => $event->types,
            'status' => $event->status,
            'summary' => $event->summary,
            'confidence' => $event->confidence,
            'uncertainty' => $event->uncertainty,
            'first_observed_at' => $event->first_observed_at?->toIso8601String(),
            'last_observed_at' => $event->last_observed_at?->toIso8601String(),
            'chat' => [
                'id' => $event->chat?->telegram_chat_id,
                'title' => $event->chat?->title,
            ],
            'topic' => $event->topic ? [
                'id' => $event->topic->telegram_thread_id,
                'title' => $event->topic->title,
            ] : null,
            'history' => $event->evidence->map(function ($evidence) {
                $message = $evidence->observation?->message;

                return [
                    'role' => $evidence->role,
                    'transition' => $evidence->transition,
                    'status_before' => $evidence->status_before,
                    'status_after' => $evidence->status_after,
                    'confidence' => $evidence->confidence,
                    'uncertainty' => $evidence->uncertainty,
                    'occurred_at' => $evidence->occurred_at?->toIso8601String(),
                    'current_revision' => $evidence->is_current_revision,
                    'source' => [
                        'local_message_id' => $message?->id,
                        'telegram_message_id' => $message?->message_id,
                        'chat_id' => $message?->chat?->telegram_chat_id,
                        'topic_id' => $message?->topic?->telegram_thread_id,
                        'sent_at' => $message?->sent_at?->toIso8601String(),
                    ],
                ];
            })->values()->all(),
        ])->values();

        $observationQuery = TelegramOperationalObservation::query()
            ->with('evidence.event:id,event_key')
            ->latest('id');

        if (filled($this->option('message'))) {
            $observationQuery->where('telegram_message_id', (int) $this->option('message'));
        }

        if ($date) {
            $observationQuery->whereHas('message', fn ($query) => $query->whereBetween('sent_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]));
        }

        if (filled($this->option('event'))) {
            $eventKey = (string) $this->option('event');
            $observationQuery->whereHas('evidence.event', fn ($query) => $query->where('event_key', $eventKey));
        }

        if (filled($this->option('status'))) {
            $status = (string) $this->option('status');
            $observationQuery->whereHas('evidence.event', fn ($query) => $query->where('status', $status));
        }

        $observationQuery->limit(50);

        $observations = $observationQuery->get()->map(fn (TelegramOperationalObservation $observation) => [
            'message_id' => $observation->telegram_message_id,
            'revision' => 'sha256:'.$observation->source_revision_hash,
            'evaluation_kind' => $observation->evaluation_kind,
            'state' => $observation->state,
            'outcome' => $observation->outcome,
            'reason_code' => $observation->reason_code,
            'confidence' => $observation->confidence,
            'uncertainty' => $observation->uncertainty,
            'current_revision' => $observation->is_current_revision,
            'processed_at' => $observation->processed_at?->toIso8601String(),
            'error_code' => $observation->error_code,
            'event_keys' => $observation->evidence->pluck('event.event_key')->filter()->unique()->values()->all(),
        ])->values();

        $payload = ['events' => $events, 'observations' => $observations];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Operational events: '.$events->count());
            foreach ($events as $event) {
                $this->line(sprintf('%s | %s | %s | %s', $event['event_key'], $event['status'], implode(',', $event['types']), $event['summary']));
            }
            $this->line('Observations: '.$observations->count());
            $this->line('Mode: read-only. Telegram actions: 0.');
        }

        return self::SUCCESS;
    }

    private function dateOption(): ?Carbon
    {
        if (! filled($this->option('date'))) {
            return null;
        }

        $value = (string) $this->option('date');

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone', 'Europe/Rome'));
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
}

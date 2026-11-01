<?php

use App\Models\TelegramOutboundOperation;
use App\Services\Telegram\TelegramDigestBuildJournal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operation_events');
    Schema::dropIfExists('telegram_outbound_operations');
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();

    $eventMigration = database_path('migrations/2026_11_01_000000_create_telegram_outbound_operation_events_table.php');
    if (is_file($eventMigration)) {
        (require $eventMigration)->up();
    }
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operation_events');
    Schema::dropIfExists('telegram_outbound_operations');
});

it('provides an event table for the existing outbound operation journal', function (): void {
    expect(Schema::hasTable('telegram_outbound_operation_events'))->toBeTrue();
});

it('exposes outbound events in insertion order', function (): void {
    $hasEventsRelation = method_exists(TelegramOutboundOperation::class, 'events');
    expect($hasEventsRelation)->toBeTrue();
    if (! $hasEventsRelation) {
        return;
    }

    $operation = TelegramOutboundOperation::query()->create([
        'operation_key' => 'calendar:tomorrow:2026-11-02',
        'notification_type' => 'calendar',
        'method' => 'sendMessage',
        'bot' => 'work',
    ]);
    $operation->events()->create(['stage' => 'calendar_source', 'outcome' => 'loaded']);
    $operation->events()->create(['stage' => 'calendar_format', 'outcome' => 'built']);

    expect($operation->events->pluck('stage')->all())->toBe(['calendar_source', 'calendar_format']);
});

it('links distinct build outcomes to one operation and discards free-form diagnostics', function (): void {
    $journalExists = class_exists(TelegramDigestBuildJournal::class);
    expect($journalExists)->toBeTrue();
    if (! $journalExists) {
        return;
    }

    $context = [
        'operation_key' => 'calendar:tomorrow:2026-11-02',
        'notification_type' => 'calendar',
        'bot' => 'work',
        'source_type' => 'CalendarDate',
        'source_id' => '2026-11-02',
    ];
    $journal = app(TelegramDigestBuildJournal::class);
    $journal->reserve($context);
    $journal->record($context, 'calendar_source', 'no_input', ['input_count' => 0]);
    $journal->record($context, 'calendar_filter', 'no_significant_events', [
        'input_count' => 5,
        'filtered_count' => 5,
        'included_count' => 0,
        'message' => 'private summary text',
        'token' => 'synthetic-token',
        'comment' => 'personal staff comment',
    ]);
    $journal->record($context, 'calendar_format', 'no_message', ['message_present' => false]);

    $operation = TelegramOutboundOperation::query()->where('operation_key', $context['operation_key'])->sole();
    $events = $operation->events;

    expect($events->pluck('stage')->all())->toBe(['build', 'calendar_source', 'calendar_filter', 'calendar_format'])
        ->and($events->pluck('outcome')->all())->toBe(['started', 'no_input', 'no_significant_events', 'no_message'])
        ->and($events[2]->metrics)->toBe(['input_count' => 5, 'filtered_count' => 5, 'included_count' => 0])
        ->and($events[3]->metrics)->toBe(['message_present' => false])
        ->and($operation->status)->toBe('expected')
        ->and(json_encode($events->toArray()))->not->toContain('private summary text', 'synthetic-token', 'personal staff comment');
});

it('fails open when the build event table is unavailable', function (): void {
    $journalExists = class_exists(TelegramDigestBuildJournal::class);
    expect($journalExists)->toBeTrue();
    if (! $journalExists) {
        return;
    }

    Schema::dropIfExists('telegram_outbound_operation_events');
    app(TelegramDigestBuildJournal::class)->record([
        'operation_key' => 'calendar:tomorrow:2026-11-02',
    ], 'calendar_source', 'source_error');

    expect(Schema::hasTable('telegram_outbound_operations'))->toBeTrue();
});

it('reserves the existing operation when the build event table is unavailable', function (): void {
    Schema::dropIfExists('telegram_outbound_operation_events');
    $context = [
        'operation_key' => 'calendar:tomorrow:2026-11-02',
        'notification_type' => 'calendar',
        'bot' => 'work',
    ];

    app(TelegramDigestBuildJournal::class)->reserve($context);

    $operation = TelegramOutboundOperation::query()->where('operation_key', $context['operation_key'])->sole();
    expect($operation->status)->toBe('expected')
        ->and(Schema::hasTable('telegram_outbound_operation_events'))->toBeFalse();
});

it('caps appended event rows for a repeatedly reserved logical operation', function (): void {
    $context = [
        'operation_key' => 'calendar:tomorrow:2026-11-02',
        'notification_type' => 'calendar',
        'bot' => 'work',
    ];
    $journal = app(TelegramDigestBuildJournal::class);
    $eventInsertWasTransactional = false;
    DB::listen(function (QueryExecuted $query) use (&$eventInsertWasTransactional): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'insert')
            && str_contains($query->sql, 'telegram_outbound_operation_events')) {
            $eventInsertWasTransactional = DB::transactionLevel() > 0;
        }
    });

    $journal->reserve($context);

    foreach (range(1, 100) as $repeat) {
        $journal->record($context, 'calendar_filter', 'complete', ['input_count' => $repeat]);
    }

    $operation = TelegramOutboundOperation::query()->where('operation_key', $context['operation_key'])->sole();
    expect($operation->events()->count())->toBe(60);

    $journal->reserve($context);

    expect(TelegramOutboundOperation::query()->count())->toBe(1)
        ->and($operation->events()->count())->toBe(60)
        ->and($eventInsertWasTransactional)->toBeTrue();
});

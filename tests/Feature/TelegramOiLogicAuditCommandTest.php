<?php

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\TelegramOperationalObservation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function (): void {
    TelegramOperationalTestDatabase::refresh();

    $GLOBALS['oi_audit_created_users_table'] = ! Schema::hasTable('users');
    if ($GLOBALS['oi_audit_created_users_table']) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('telegram_id')->nullable()->unique();
        });
    }

    DB::table('users')->insert(['name' => 'Анна', 'telegram_id' => '101']);
});

afterEach(function (): void {
    TelegramOperationalTestDatabase::purge();

    if ($GLOBALS['oi_audit_created_users_table'] ?? false) {
        Schema::dropIfExists('users');
    }

    unset($GLOBALS['oi_audit_created_users_table']);
});

it('compares stored links and current decisions without mutations, Telegram calls or jobs', function (): void {
    [$event, $message] = createOiAuditEvent('Не работает замок в квартире.', 'Не работает замок в квартире.');
    $interpreter = app(\App\Services\Telegram\TelegramOperationalInterpreter::class);
    $decision = $interpreter->interpret($message->text);
    $event->update(['subject_key' => $decision['subject_key']]);
    $new = TelegramOperationalTestDatabase::message('Не работает свет в комнате 2.', '2026-09-24 11:00:00', '102');
    $ignored = TelegramOperationalTestDatabase::message('Спасибо!', '2026-09-24 12:00:00', '103');
    TelegramOperationalTestDatabase::message('Не работает свет.', '2026-09-23 10:00:00', '104');
    TelegramOperationalTestDatabase::message('Не работает свет.', '2026-09-24 10:00:00', '105', chatId: '-1002');
    TelegramOperationalTestDatabase::message('Не работает свет.', '2026-09-24 10:00:00', '106', chatId: '-999');
    config(['services.telegram.digest_districts' => ['certosa' => [
        'label' => 'Certosa', 'chat_id' => '-1001', 'latitude' => 45, 'longitude' => 9,
    ]]]);
    \Illuminate\Support\Facades\Http::fake();
    \Illuminate\Support\Facades\Queue::fake();
    $this->mock(\App\Services\Telegram\TelegramOperationalEventObserver::class)->shouldNotReceive('observe');
    $tables = ['telegram_messages', 'telegram_operational_events', 'telegram_operational_observations', 'telegram_operational_event_evidence', 'telegram_topics'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::connection('analytics')->table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    \Carbon\Carbon::setTestNow('2026-10-05');
    try {
        expect(Artisan::call('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--district' => 'certosa', '--compare-current' => true, '--json' => true]))->toBe(0);
        $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $rows = collect($audit['comparisons'])->keyBy('message_id');
        expect($audit['read_only'])->toBeTrue()
            ->and($audit['date'])->toBe('2026-09-24')
            ->and($audit['messages_inspected'])->toBe(3)
            ->and($rows[$message->id]['comparison'])->toBe(['same_event'])
            ->and($rows[$new->id]['comparison'])->toBe(['historical_no_event_now_event'])
            ->and($rows[$ignored->id]['comparison'])->toBe(['same_no_event'])
            ->and($rows[$ignored->id]['current']['outcome'])->toBe('no_event')
            ->and($rows[$ignored->id]['current']['reason_code'])->not->toBeNull()
            ->and($snapshot())->toBe($before);
        \Illuminate\Support\Facades\Http::assertNothingSent();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('exposes stale historical events now ignored and every evidence link', function (): void {
    [$event, $message] = createOiAuditEvent('Historical problem', 'Да, думаю не проблема будет');
    $second = $event->replicate();
    $second->event_key = 'audit:second';
    $second->save();
    $link = $event->evidence()->firstOrFail()->replicate();
    $link->operational_event_id = $second->id;
    $link->save();
    expect(Artisan::call('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--compare-current' => true, '--json' => true]))->toBe(0);
    $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $row = $audit['comparisons'][0];
    expect($row['comparison'])->toBe(['historical_event_now_no_event'])
        ->and($row['changed'])->toBeTrue()
        ->and($row['historical'][0]['evidence'])->toHaveCount(2)
        ->and($row['historical_root_events'])->toHaveCount(2)
        ->and($row['current']['outcome'])->toBe('no_event');
    $this->artisan('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--compare-current' => true])
        ->expectsOutputToContain('historical_event_now_no_event')
        ->expectsOutputToContain('Read-only: yes. Ledger mutations: 0. Telegram actions: 0.')
        ->assertExitCode(0);
});

it('reports concrete stored field differences', function (string $field, string $value, string $category): void {
    [$event, $message] = createOiAuditEvent('Не работает замок в квартире.', 'Не работает замок в квартире.');
    $decision = app(\App\Services\Telegram\TelegramOperationalInterpreter::class)->interpret($message->text);
    $event->update(['subject_key' => $decision['subject_key']]);
    $link = $event->evidence()->firstOrFail();
    if (in_array($field, ['primary_type', 'subject_key'], true)) {
        $event->update([$field => $value]);
    } elseif ($field === 'reason_code') {
        $link->observation->update([$field => $value]);
    } else {
        $link->update([$field => $value]);
    }
    Artisan::call('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--compare-current' => true, '--json' => true]);
    $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($audit['comparisons'][0]['comparison'])->toBe([$category]);
})->with([
    ['primary_type', 'risk', 'type_changed'],
    ['role', 'action', 'role_changed'],
    ['subject_key', 'different_subject', 'subject_changed'],
    ['confidence', 'low', 'confidence_changed'],
    ['reason_code', 'old_reason', 'interpretation_changed'],
]);

it('bounds the source scan and announces truncation', function (): void {
    $message = TelegramOperationalTestDatabase::message('Спасибо!', '2026-09-24 08:00:00', '1');
    $attributes = $message->getAttributes();
    unset($attributes['id']);
    $rows = [];
    foreach (range(2, 501) as $id) {
        $rows[] = array_replace($attributes, ['message_id' => (string) $id]);
    }
    DB::connection('analytics')->table('telegram_messages')->insert($rows);
    Artisan::call('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--compare-current' => true, '--json' => true]);
    $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($audit['messages_inspected'])->toBe(500)
        ->and($audit['truncated'])->toBeTrue()
        ->and($audit['counts']['same_no_event'])->toBe(500);
});

it('exposes observations without evidence and root events without stored interpretation', function (): void {
    [$event, $message] = createOiAuditEvent('Historical problem', 'Спасибо!');
    $event->evidence()->delete();
    Artisan::call('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--event' => $event->id, '--compare-current' => true, '--json' => true]);
    $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($audit['comparisons'])->toHaveCount(1)
        ->and($audit['comparisons'][0]['historical'][0]['evidence'])->toBeEmpty()
        ->and($audit['comparisons'][0]['historical_root_events'])->toHaveCount(1)
        ->and($audit['comparisons'][0]['comparison'])->toBe(['historical_event_now_no_event']);
});

function createOiAuditEvent(string $summary, string $messageText, string $at = '2026-09-24 10:13:00'): array
{
    $message = TelegramOperationalTestDatabase::message(
        $messageText,
        sentAt: $at,
        messageId: '101',
        threadId: '11',
        userId: '101',
    );
    $event = TelegramOperationalEvent::query()->create([
        'event_key' => 'audit:'.$message->id,
        'root_message_id' => $message->id,
        'telegram_chat_id' => $message->telegram_chat_id,
        'telegram_topic_id' => $message->telegram_topic_id,
        'primary_type' => 'problem',
        'types' => ['problem'],
        'summary' => $summary,
        'status' => 'open',
        'confidence' => 'high',
        'subject_key' => 'lock',
        'first_observed_at' => $at,
        'last_observed_at' => $at,
    ]);
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $message->id,
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message',
        'state' => 'completed',
        'outcome' => 'created',
        'reason_code' => 'operational_problem',
        'confidence' => 'high',
        'is_current_revision' => true,
        'processed_at' => $at,
    ]);
    TelegramOperationalEventEvidence::query()->create([
        'operational_event_id' => $event->id,
        'observation_id' => $observation->id,
        'role' => 'report',
        'transition' => 'created',
        'status_after' => 'open',
        'confidence' => 'high',
        'occurred_at' => $at,
        'is_current_revision' => true,
    ]);

    return [$event, $message];
}

it('shows builder decisions, selected evidence, and only same-chat same-topic context without writing', function (): void {
    [$event, $source] = createOiAuditEvent('Не работает замок в квартире.', 'Не работает замок в квартире.');
    TelegramOperationalTestDatabase::message('До события: дверь была закрыта.', '2026-09-24 10:10:00', '100', threadId: '11');
    TelegramOperationalTestDatabase::message('Соседняя тема с похожим текстом.', '2026-09-24 10:14:00', '102', threadId: '12');
    TelegramOperationalTestDatabase::message('Другой чат.', '2026-09-24 10:15:00', '103', chatId: '-1002', threadId: '11');
    TelegramOperationalTestDatabase::message('После события: дверь открыли.', '2026-09-24 10:17:00', '104', threadId: '11');

    $before = [
        DB::connection('analytics')->table('telegram_operational_events')->count(),
        DB::connection('analytics')->table('telegram_operational_observations')->count(),
        DB::connection('analytics')->table('telegram_operational_event_evidence')->count(),
    ];

    $this->artisan('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Не работает замок в квартире.')
        ->expectsOutputToContain('selected_quote')
        ->expectsOutputToContain('Анна')
        ->expectsOutputToContain('telegram_actions');

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $audited = collect($result['events'])->firstWhere('event_key', $event->event_key);
    $conversation = collect($audited['related_conversation']);

    expect($audited['current_oi']['state'])->toBe('open')
        ->and($audited['current_oi']['needs_attention'])->toBeTrue()
        ->and($audited['current_oi']['selected_quote'])->not->toBeNull()
        ->and($audited['current_oi']['selected_evidence']['telegram_message_id'])->toBe($source->message_id)
        ->and($audited['current_oi']['author'])->toBe('Анна')
        ->and($audited['current_oi']['rendered_in'])->toContain('Требует внимания')
        ->and($conversation->pluck('telegram_message_id')->all())->toContain('100', '101', '104')
        ->and($conversation->pluck('telegram_message_id')->all())->not->toContain('102', '103')
        ->and($result['mode'])->toMatchArray(['read_only' => true, 'ledger_mutations' => 0, 'telegram_actions' => 0])
        ->and([
            DB::connection('analytics')->table('telegram_operational_events')->count(),
            DB::connection('analytics')->table('telegram_operational_observations')->count(),
            DB::connection('analytics')->table('telegram_operational_event_evidence')->count(),
        ])->toBe($before);
});

it('reports resolution transitions and safely handles missing apartment, author, and evidence', function (): void {
    [$event] = createOiAuditEvent('Не работает замок в квартире.', 'Не работает замок в квартире.');
    $resolution = TelegramOperationalTestDatabase::message(
        'Всё, дверь открыли.',
        '2026-09-24 10:22:00',
        '105',
        ['message' => ['reply_to_message' => ['message_id' => 101]]],
        threadId: '11',
    );
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $resolution->id,
        'source_revision_hash' => str_repeat('b', 64),
        'evaluation_kind' => 'message',
        'state' => 'completed',
        'outcome' => 'resolved',
        'reason_code' => 'operational_resolution',
        'confidence' => 'high',
        'is_current_revision' => true,
        'processed_at' => '2026-09-24 10:22:00',
    ]);
    $event->evidence()->create([
        'observation_id' => $observation->id,
        'role' => 'resolution',
        'transition' => 'resolved',
        'status_before' => 'open',
        'status_after' => 'resolved',
        'confidence' => 'high',
        'occurred_at' => '2026-09-24 10:22:00',
        'is_current_revision' => true,
    ]);
    $event->update(['status' => 'resolved', 'resolved_at' => '2026-09-24 10:22:00']);

    $withoutEvidence = TelegramOperationalTestDatabase::message(
        'Нужно проверить сломанный замок.',
        '2026-09-24 11:00:00',
        '106',
        threadId: '11',
    );
    TelegramOperationalEvent::query()->create([
        'event_key' => 'audit:no-evidence',
        'root_message_id' => $withoutEvidence->id,
        'telegram_chat_id' => $withoutEvidence->telegram_chat_id,
        'telegram_topic_id' => $withoutEvidence->telegram_topic_id,
        'primary_type' => 'problem',
        'types' => ['problem'],
        'summary' => 'Нужно проверить сломанный замок.',
        'status' => 'open',
        'confidence' => 'low',
        'first_observed_at' => '2026-09-24 11:00:00',
        'last_observed_at' => '2026-09-24 11:00:00',
    ]);

    $this->artisan('telegram:oi-logic-audit', ['--date' => '2026-09-24', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('resolution')
        ->expectsOutputToContain('telegram_actions');

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $resolved = collect($result['events'])->firstWhere('event_key', $event->event_key);
    $empty = collect($result['events'])->firstWhere('event_key', 'audit:no-evidence');

    expect($resolved['current_oi']['state'])->toBe('resolved')
        ->and($resolved['current_oi']['rendered_in'])->toContain('Решено сегодня')
        ->and(collect($resolved['evidence'])->pluck('transition')->all())->toContain('created', 'resolved')
        ->and($empty['apartment'])->toBeNull()
        ->and($empty['current_oi']['author'])->toBeNull()
        ->and($empty['evidence'])->toBe([])
        ->and($empty['related_conversation'])->toBe([])
        ->and($result['counts']['missing_evidence'])->toBe(1)
        ->and($result['mode']['telegram_actions'])->toBe(0);
});

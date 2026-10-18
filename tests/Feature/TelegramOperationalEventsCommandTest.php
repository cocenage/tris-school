<?php

use App\Services\Operations\OperationalContextBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(fn () => TelegramOperationalTestDatabase::refresh());
afterEach(fn () => TelegramOperationalTestDatabase::purge());

it('inspects event evidence without raw payload or full message text', function () {
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире');
    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    $this->artisan('telegram:operational-events', ['--event' => $result['event_key'], '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain($result['event_key'])
        ->doesntExpectOutputToContain('Не работает замок')
        ->doesntExpectOutputToContain('"raw"');
});

it('inspects no-event decisions by source message', function () {
    $message = TelegramOperationalTestDatabase::message('Всем привет');
    app(TelegramOperationalEventObserver::class)->observe($message);

    $this->artisan('telegram:operational-events', ['--message' => (string) $message->id, '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('ordinary_conversation');

    expect(DB::connection('analytics')->table('telegram_operational_observations')->count())->toBe(1);
});

it('filters inspected messages by the Europe Rome calendar date over UTC storage', function () {
    $messages = collect([
        ['2026-06-16 23:59:59', '901'],
        ['2026-06-17 00:00:00', '902'],
        ['2026-06-17 23:59:59', '903'],
        ['2026-06-18 00:00:00', '904'],
    ])->map(fn (array $entry) => TelegramOperationalTestDatabase::message(
        'Всем привет',
        $entry[0],
        $entry[1],
    ));

    foreach ($messages as $message) {
        app(TelegramOperationalEventObserver::class)->observe($message);
    }

    expect(Artisan::call('telegram:operational-events', [
        '--date' => '2026-06-17',
        '--json' => true,
    ]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($output['observations'])->pluck('message_id')->sort()->values()->all())
        ->toBe($messages->slice(1, 2)->pluck('id')->sort()->values()->all());
});

it('counts operational Telegram context using Rome calendar boundaries over UTC storage', function () {
    TelegramOperationalTestDatabase::message('Всем привет', '2026-06-16 23:59:59', '911');
    TelegramOperationalTestDatabase::message('не работает замок в квартире', '2026-06-17 00:00:00', '912');
    TelegramOperationalTestDatabase::message('Всем привет', '2026-06-17 23:59:59', '913');
    TelegramOperationalTestDatabase::message('Всем привет', '2026-06-18 00:00:00', '914');

    $builder = app(OperationalContextBuilder::class);
    $telegram = new ReflectionMethod($builder, 'telegram');
    $telegram->setAccessible(true);
    $result = $telegram->invoke($builder, Carbon::parse('2026-06-17', 'Europe/Rome'));

    expect($result['messages'])->toBe(2)
        ->and($result['signals']['potential_problems']['count'])->toBe(1);
});

it('combines inspection filters instead of treating them as alternatives', function () {
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире', messageId: '901');
    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    expect(Artisan::call('telegram:operational-events', [
        '--event' => 'telegram:-1001:not-this-event',
        '--message' => (string) $message->id,
        '--date' => '2026-06-17',
        '--status' => 'open',
        '--json' => true,
    ]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['event_key'])->not->toBeNull()
        ->and($output['events'])->toBe([])
        ->and($output['observations'])->toBe([]);
});

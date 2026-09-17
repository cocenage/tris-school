<?php

use App\Services\Telegram\TelegramOperationalEventObserver;
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

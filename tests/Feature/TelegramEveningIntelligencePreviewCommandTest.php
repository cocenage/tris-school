<?php

use App\Services\Telegram\TelegramOperationalEventObserver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(fn () => TelegramOperationalTestDatabase::refresh());
afterEach(fn () => TelegramOperationalTestDatabase::purge());

it('requires one explicit valid date before projection', function (array $arguments, string $message) {
    $this->artisan('telegram:evening-intelligence-preview', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);
})->with([
    'missing' => [[], 'Date is required'],
    'invalid format' => [['--date' => '17-06-2026'], 'Date must use YYYY-MM-DD'],
    'invalid calendar date' => [['--date' => '2026-02-30'], 'Date must be a valid YYYY-MM-DD date'],
]);

it('emits the JSON contract without changing source or ledger rows', function () {
    Http::fake();
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире');
    app(TelegramOperationalEventObserver::class)->observe($message);

    $tables = [
        'telegram_messages',
        'telegram_operational_events',
        'telegram_operational_observations',
        'telegram_operational_event_evidence',
    ];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [
        $table => DB::connection('analytics')->table($table)->count(),
    ])->all();

    expect(Artisan::call('telegram:evening-intelligence-preview', [
        '--date' => '2026-06-17',
        '--json' => true,
    ]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $after = collect($tables)->mapWithKeys(fn (string $table) => [
        $table => DB::connection('analytics')->table($table)->count(),
    ])->all();

    expect($output)
        ->toHaveKeys(['date', 'timezone', 'district', 'events', 'sections', 'events_considered', 'events_included', 'events_omitted', 'no_material_events', 'data_quality', 'mode'])
        ->and($output['date'])->toBe('2026-06-17')
        ->and($output['mode'])->toBe(['read_only' => true, 'telegram_actions' => 0, 'mutations' => 0])
        ->and($output['sections'])->not->toBeEmpty()
        ->and($before)->toBe($after)
        ->and(Http::recorded())->toHaveCount(0)
        ->and(Artisan::output())->not->toContain('"raw"');
});

it('renders only non-empty human sections and an explicit read-only footer', function () {
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире');
    app(TelegramOperationalEventObserver::class)->observe($message);

    $this->artisan('telegram:evening-intelligence-preview', ['--date' => '2026-06-17'])
        ->expectsOutputToContain('TRIS — итоги дня')
        ->expectsOutputToContain('Не работает замок')
        ->doesntExpectOutputToContain('Событие:')
        ->doesntExpectOutputToContain('Доказательства:')
        ->doesntExpectOutputToContain('статус:')
        ->doesntExpectOutputToContain('уверенность:')
        ->doesntExpectOutputToContain('Положительный вклад')
        ->expectsOutputToContain('Предпросмотр: отправка в Telegram отключена')
        ->assertExitCode(0);
});

it('filters a configured district while keeping complete technical evidence in json', function () {
    config(['services.telegram.digest_districts' => [
        'navigli' => [
            'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
            'latitude' => 45.45, 'longitude' => 9.17,
        ],
    ]]);

    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Lodi', messageId: '2', chatId: '-1002'),
    );

    expect(Artisan::call('telegram:evening-intelligence-preview', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($output['district'])->toMatchArray(['key' => 'navigli', 'label' => 'Navigli'])
        ->and($output['events_considered'])->toBe(1)
        ->and($output['events'])->toHaveCount(1)
        ->and($output['events'][0])->toHaveKeys(['event_key', 'status', 'confidence', 'evidence'])
        ->and($output['events'][0]['summary'])->toContain('Navigli');
});

it('fails cleanly when the required ledger is unavailable', function () {
    Schema::connection('analytics')->dropIfExists('telegram_operational_event_evidence');
    Schema::connection('analytics')->dropIfExists('telegram_operational_events');

    $this->artisan('telegram:evening-intelligence-preview', ['--date' => '2026-06-17'])
        ->expectsOutputToContain('Operational event ledger is unavailable')
        ->assertExitCode(1);
});

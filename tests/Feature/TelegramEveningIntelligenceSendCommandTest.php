<?php

use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => false,
        'services.telegram.digest_districts' => [
            'navigli' => [
                'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
                'latitude' => 45.45, 'longitude' => 9.17,
            ],
            'lodi' => [
                'label' => 'Lodi', 'chat_id' => '-1002', 'duty_thread_id' => '22',
                'latitude' => 45.44, 'longitude' => 9.21,
            ],
        ],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
});

it('dry-runs one district without calling telegram or leaking another forum', function () {
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Lodi', messageId: '2', chatId: '-1002'),
    );
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    $exit = Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--dry-run' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Navigli')
        ->toContain('Не работает замок в Navigli')
        ->not->toContain('Не работает замок в Lodi');
});

it('blocks real delivery while the feature flag is off', function () {
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    $this->artisan('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli',
    ])
        ->expectsOutputToContain('disabled')
        ->assertExitCode(1);
});

it('skips an empty district digest even when delivery is enabled', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['results'][0])->toMatchArray([
        'district' => 'navigli',
        'status' => 'skipped_empty',
        'telegram_actions' => 0,
    ]);
});

it('sends one non-empty summary to the configured duty topic through the existing sender', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    $this->mock(TelegramBotService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendMessage')
            ->once()
            ->with('-1001', Mockery::on(fn (string $text): bool => str_contains($text, 'Navigli')), '11')
            ->andReturn(123);
    });

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['results'][0])->toMatchArray([
        'district' => 'navigli',
        'status' => 'sent',
        'telegram_actions' => 1,
    ]);
});

it('catches up the frozen current day before attempting evening delivery', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 20:30:00', 'Europe/Rome'));
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.operational_chat_ids' => ['-1001'],
    ]);
    TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-07-23 08:00:00');
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')
        ->once()
        ->with('-1001', Mockery::on(fn (string $text): bool => str_contains($text, 'Не работает замок')), '11')
        ->andReturn(123));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-07-23', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0)
        ->and(TelegramOperationalObservation::query()->count())->toBe(1);
});

it('stops current-day delivery if catch-up has message failures', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 20:30:00', 'Europe/Rome'));
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-07-23 08:00:00');
    $observer = Mockery::mock(TelegramOperationalEventObserver::class);
    $observer->shouldReceive('observe')->once()->andThrow(new RuntimeException('fixture failure'));
    $this->app->instance(TelegramOperationalEventObserver::class, $observer);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-07-23', '--district' => 'navigli', '--json' => true,
    ]))->toBe(1);
});

it('schedules the current-day catch-up before the evening send', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('telegram:operational-replay --through-now')
        ->toContain('telegram:evening-intelligence-send')
        ->and(strpos($output, 'telegram:operational-replay --through-now'))
        ->toBeLessThan(strpos($output, 'telegram:evening-intelligence-send'));
});

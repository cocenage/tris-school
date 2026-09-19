<?php

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOperationalEventObserver;
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

afterEach(fn () => TelegramOperationalTestDatabase::purge());

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
        ->toContain('Возникла проблема: не работает замок в Navigli')
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

<?php

use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    Cache::flush();
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => false,
        'services.telegram.evening_intelligence_delivery_mode' => 'centralized',
        'services.telegram.evening_intelligence_central_chat_id' => '-9000',
        'services.telegram.evening_intelligence_central_thread_id' => '99',
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
    config([
        'services.telegram.evening_intelligence_central_chat_id' => null,
        'services.telegram.evening_intelligence_central_thread_id' => null,
    ]);
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
            ->with('-9000', Mockery::on(fn (string $text): bool => str_contains($text, 'Navigli')), '99')
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

it('does not send a second district summary for the same day and destination', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    $attempts = 0;
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$attempts): void {
        $mock->shouldReceive('sendMessage')->andReturnUsing(function () use (&$attempts): int {
            $attempts++;

            return 123;
        });
    });

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'][0];

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'][0];

    expect($first['status'])->toBe('sent')
        ->and($second['status'])->toBe('skipped_duplicate')
        ->and($second['telegram_actions'])->toBe(0)
        ->and($attempts)->toBe(1);
});

it('does not retry an unconfirmed delivery attempt automatically', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    $attempts = 0;
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$attempts): void {
        $mock->shouldReceive('sendMessage')->andReturnUsing(function () use (&$attempts): ?int {
            $attempts++;

            return null;
        });
    });

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(1);
    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(1);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'][0];

    expect($second['status'])->toBe('blocked_uncertain')
        ->and($second['telegram_actions'])->toBe(0)
        ->and($attempts)->toBe(1);
});

it('routes five independently filtered district summaries to one central duty topic', function () {
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.digest_districts' => collect(['navigli', 'lodi', 'como', 'certosa', 'lambrate'])
            ->mapWithKeys(fn (string $key, int $index): array => [$key => [
                'label' => ucfirst($key),
                'chat_id' => (string) (-1001 - $index),
                'duty_thread_id' => null,
                'latitude' => 45.45,
                'longitude' => 9.17,
            ]])->all(),
    ]);

    foreach (['navigli', 'lodi', 'como', 'certosa', 'lambrate'] as $index => $district) {
        app(TelegramOperationalEventObserver::class)->observe(
            TelegramOperationalTestDatabase::message(
                'Не работает замок в '.ucfirst($district),
                messageId: (string) (201 + $index),
                chatId: (string) (-1001 - $index),
            )
        );
    }

    $sent = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$sent): void {
        $mock->shouldReceive('sendMessage')
            ->times(5)
            ->withArgs(function (string $chatId, string $text, string $threadId) use (&$sent): bool {
                $sent[] = compact('chatId', 'text', 'threadId');

                return true;
            })
            ->andReturn(123);
    });

    expect(Artisan::call('telegram:evening-intelligence-send', ['--date' => '2026-06-17', '--json' => true]))->toBe(0);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];

    expect($results)->toHaveCount(5)
        ->and(array_column($results, 'status'))->toBe(['sent', 'sent', 'sent', 'sent', 'sent'])
        ->and(array_sum(array_column($results, 'telegram_actions')))->toBe(5)
        ->and(collect($sent)->pluck('chatId')->unique()->all())->toBe(['-9000'])
        ->and(collect($sent)->pluck('threadId')->unique()->all())->toBe(['99']);

    foreach (['Navigli', 'Lodi', 'Como', 'Certosa', 'Lambrate'] as $index => $label) {
        expect($sent[$index]['text'])->toContain('🌙 '.$label.' — итоги дня')
            ->toContain('Не работает замок в '.$label);
    }
});

it('requires a configured central target before any real delivery', function () {
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.evening_intelligence_central_thread_id' => null,
    ]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', ['--date' => '2026-06-17']))->toBe(1);
});

it('counts no Telegram action when the Academy bot cannot deliver', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')
        ->once()
        ->andReturn(null));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(1);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'][0];
    expect($result['status'])->toBe('failed')
        ->and($result['telegram_actions'])->toBe(0);
});

it('can switch back to preserved per-district delivery without changing source routes', function () {
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.evening_intelligence_delivery_mode' => 'per-district',
    ]);
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Не работает замок в Navigli', chatId: '-1001'),
    );
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendAnalyticsMessage')
        ->once()
        ->with('-1001', Mockery::on(fn (string $text): bool => str_contains($text, 'Navigli')), '11')
        ->andReturn(123));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-06-17', '--district' => 'navigli', '--json' => true,
    ]))->toBe(0);
});

it('uses the Academy bot token for centralized operational evening transport', function () {
    config([
        'services.telegram.bot_token' => 'main-test-token',
        'services.telegram.analytics_bot_token' => 'analytics-test-token',
    ]);
    Http::fake(['*' => Http::response(['result' => ['message_id' => 123]], 200)]);

    expect(app(TelegramBotService::class)->sendMessage('-9000', 'Test summary', '99'))->toBe(123);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botmain-test-token/sendMessage')
        && ! str_contains($request->url(), 'analytics-test-token')
        && $request['chat_id'] === '-9000'
        && $request['message_thread_id'] === 99);
    Http::assertSentCount(1);
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
        ->with('-9000', Mockery::on(fn (string $text): bool => str_contains($text, 'Не работает замок')), '99')
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

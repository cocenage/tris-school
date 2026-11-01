<?php

use App\Services\Telegram\TelegramPollingHealthMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pollingHealthCachePath = storage_path('framework/testing/telegram-polling-health-'.Str::uuid());

    config([
        'cache.default' => 'database',
        'cache.stores.file.path' => $this->pollingHealthCachePath,
        'app.timezone' => 'Europe/Rome',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->pollingHealthCachePath);
    CarbonImmutable::setTestNow();
});

it('reports unknown before observations, working after success, and partial degradation after a network failure', function () {
    $monitor = app(TelegramPollingHealthMonitor::class);

    expect($monitor->snapshot('work')['status'])->toBe('unknown');

    $monitor->recordSuccess('work', 42, 1);

    expect($monitor->snapshot('work')['status'])->toBe('working');

    $monitor->recordFailure('work', 'fetch', ConnectionException::class, 3000, 1, 28);

    expect($monitor->snapshot('work'))
        ->toMatchArray([
            'status' => 'partially_degraded',
            'last_failure_stage' => 'fetch',
            'last_exception' => 'ConnectionException',
            'last_connection_error_code' => 28,
            'recent_issues' => 1,
        ]);
});

it('reports unavailable after the last successful polling cycle becomes stale', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00:00', 'Europe/Rome'));
    $monitor = app(TelegramPollingHealthMonitor::class);
    $monitor->recordSuccess('analytics', 80, 0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:11:00', 'Europe/Rome'));

    expect($monitor->snapshot('analytics')['status'])->toBe('unavailable');
});

it('records successful outcomes for all three polling commands without message data', function () {
    config([
        'cache.default' => 'array',
        'services.telegram.bot_token' => 'fake-work-token',
        'services.telegram.analytics_bot_token' => 'fake-analytics-token',
        'services.telegram.scheduled_bot_token' => 'fake-scheduled-token',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(Artisan::call('telegram:analytics-poll'))->toBe(0)
        ->and(Artisan::call('telegram:scheduled-poll'))->toBe(0);

    $health = app(TelegramPollingHealthMonitor::class)->all();

    expect($health['work']['status'])->toBe('working')
        ->and($health['analytics']['status'])->toBe('working')
        ->and($health['scheduled']['status'])->toBe('working')
        ->and($health['work']['last_processed'])->toBe(0)
        ->and(Http::recorded())->toHaveCount(3);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getUpdates'));
});

it('reports an unavailable bot after a failed polling cycle without treating it as successful', function () {
    config([
        'cache.default' => 'array',
        'services.telegram.bot_token' => 'fake-work-token',
    ]);
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: connect timeout for https://api.telegram.org/botfake-secret-token/getUpdates');
    });

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and($attempts)->toBe(2)
        ->and(app(TelegramPollingHealthMonitor::class)->snapshot('work'))
        ->toMatchArray([
            'status' => 'unavailable',
            'last_failure_stage' => 'fetch',
            'last_exception' => 'ConnectionException',
            'last_connection_error_code' => 28,
            'last_connection_retries' => 1,
        ]);
});

it('does not throw when health telemetry storage fails', function () {
    Cache::shouldReceive('store')
        ->once()
        ->with('file')
        ->andThrow(new RuntimeException('health store unavailable'));

    Log::shouldReceive('warning')
        ->once()
        ->with('Telegram polling health state unavailable', Mockery::on(
            fn (array $context): bool => $context === [
                'poller' => 'work',
                'exception' => 'RuntimeException',
            ],
        ));

    app(TelegramPollingHealthMonitor::class)->recordSuccess('work', 12, 0);

    expect(true)->toBeTrue();
});

it('keeps health state on file cache even when the default cache store is database', function () {
    $monitor = app(TelegramPollingHealthMonitor::class);
    $monitor->recordSuccess('scheduled', 60, 2);

    expect($monitor->snapshot('scheduled'))
        ->toMatchArray([
            'status' => 'working',
            'last_processed' => 2,
        ])
        ->and(Artisan::all())->toHaveKey('telegram:polling-health')
        ->and(Artisan::call('telegram:polling-health'))->toBe(0)
        ->and(Artisan::output())->toContain('Scheduled Bot', 'Работает', 'Europe/Rome');
});

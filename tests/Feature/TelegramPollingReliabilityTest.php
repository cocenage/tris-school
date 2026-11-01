<?php

use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('retries a polling connection failure once with the same offset before processing', function () {
    $calls = 0;
    $offsets = [];
    $processed = [];

    Http::fake(function (ClientRequest $request) use (&$calls, &$offsets) {
        $calls++;
        $offsets[] = ($request->data()['offset'] ?? null);

        if ($calls === 1) {
            throw new ConnectionException('simulated transient network failure');
        }

        return Http::response(['ok' => true, 'result' => $calls === 2
            ? [['update_id' => 501, 'message' => ['message_id' => 11]]]
            : []]);
    });

    $result = app(TelegramUpdatePoller::class)->poll(
        'test-token',
        ['message'],
        10,
        function (array $update) use (&$processed): void {
            $processed[] = $update['update_id'];
        },
        offset: 500,
    );

    expect($result)->toBe([
        'processed' => 1,
        'network_retries' => 1,
    ])
        ->and($processed)->toBe([501])
        ->and($calls)->toBe(3)
        ->and($offsets)->toBe([500, 500, 502]);
});

it('logs only a safe numeric cURL code from a connection exception', function () {
    Log::spy();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        if ($calls === 1) {
            throw new ConnectionException('cURL error 28: connect timeout for https://api.telegram.org/botfake-secret-token/getUpdates');
        }

        return Http::response(['ok' => true, 'result' => []]);
    });

    $result = app(TelegramUpdatePoller::class)->poll(
        'fake-secret-token',
        ['message'],
        10,
        fn () => null,
        pollerName: 'analytics',
    );

    expect($result)->toBe(['processed' => 0, 'network_retries' => 1]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Telegram polling connection exception', Mockery::on(
            fn (array $context): bool => $context === [
                'poller' => 'analytics',
                'stage' => 'fetch',
                'exception' => 'ConnectionException',
                'connection_error_code' => 28,
                'retry_attempt' => 1,
            ],
        ));
});

it('retries an acknowledgement with the same next offset without reprocessing the completed update', function () {
    $calls = 0;
    $offsets = [];
    $processed = [];

    Http::fake(function (ClientRequest $request) use (&$calls, &$offsets) {
        $calls++;
        $offsets[] = ($request->data()['offset'] ?? null);

        if ($calls === 2) {
            throw new ConnectionException('simulated acknowledgement timeout');
        }

        return Http::response(['ok' => true, 'result' => $calls === 1
            ? [['update_id' => 501, 'message' => ['message_id' => 11]]]
            : []]);
    });

    $result = app(TelegramUpdatePoller::class)->poll(
        'test-token',
        ['message'],
        10,
        function (array $update) use (&$processed): void {
            $processed[] = $update['update_id'];
        },
    );

    expect($result)->toBe([
        'processed' => 1,
        'network_retries' => 1,
    ])
        ->and($processed)->toBe([501])
        ->and($calls)->toBe(3)
        ->and($offsets)->toBe([null, 502, 502]);
});

it('keeps acknowledgement uncertain when its bounded retry also fails', function () {
    $calls = 0;
    $offsets = [];
    $processorCalls = 0;

    Http::fake(function (ClientRequest $request) use (&$calls, &$offsets) {
        $calls++;
        $offsets[] = ($request->data()['offset'] ?? null);

        if ($calls > 1) {
            throw new ConnectionException('simulated acknowledgement outage');
        }

        return Http::response(['ok' => true, 'result' => [
            ['update_id' => 501, 'message' => ['message_id' => 11]],
        ]]);
    });

    try {
        app(TelegramUpdatePoller::class)->poll(
            'test-token',
            ['message'],
            10,
            function () use (&$processorCalls): void {
                $processorCalls++;
            },
        );

        test()->fail('Expected acknowledgement failure was not raised.');
    } catch (TelegramPollingFailure $error) {
        expect($error->stage)->toBe('acknowledge')
            ->and($error->connectionRetries)->toBe(1)
            ->and($error->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    expect($calls)->toBe(3)
        ->and($offsets)->toBe([null, 502, 502])
        ->and($processorCalls)->toBe(1);
});

it('bounds connection retries and leaves failed fetch updates unprocessed', function () {
    $calls = 0;
    $processorCalls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('simulated network outage');
    });

    try {
        app(TelegramUpdatePoller::class)->poll(
            'test-token',
            ['message'],
            10,
            function () use (&$processorCalls): void {
                $processorCalls++;
            },
        );

        test()->fail('Expected polling failure was not raised.');
    } catch (TelegramPollingFailure $error) {
        expect($error->stage)->toBe('fetch')
            ->and($error->connectionRetries)->toBe(1)
            ->and($error->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    expect($calls)->toBe(2)
        ->and($processorCalls)->toBe(0);
});

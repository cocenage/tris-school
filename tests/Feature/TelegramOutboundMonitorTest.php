<?php

use App\Models\DayOffRequest;
use App\Models\TelegramOutboundOperation;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operations');
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    config(['services.telegram.bot_token' => 'work-test-token']);
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operations');
});

it('records API acceptance without retaining message text or token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 24]], 200)]);
    config(['services.telegram.digest_districts' => ['lodi' => ['chat_id' => 'test-chat']]]);

    expect(app(TelegramBotService::class)->sendMessage('test-chat', 'sensitive text', '7'))->toBe(24);

    $row = TelegramOutboundOperation::query()->sole();
    expect($row->status)->toBe('sent')
        ->and($row->bot)->toBe('work')
        ->and($row->chat_id)->toBe('test-chat')
        ->and($row->district)->toBe('lodi')
        ->and($row->message_thread_id)->toBe('7')
        ->and($row->telegram_message_id)->toBe(24)
        ->and(json_encode($row->getAttributes()))->not->toContain('sensitive text', 'work-test-token');
});

it('records direct HTTP sending and explicit API rejection', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403], 403)]);

    Http::post('https://api.telegram.org/botwork-test-token/sendMessage', [
        'chat_id' => 'test-chat', 'text' => 'private',
    ]);

    $row = TelegramOutboundOperation::query()->sole();
    expect($row->status)->toBe('failed')
        ->and($row->error_code)->toBe(403)
        ->and($row->error_reason)->toBe('telegram_api_rejected');
});

it('does not invent a district when a chat belongs to several configured routes', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 24]])]);
    config(['services.telegram.digest_districts' => [
        'lodi' => ['chat_id' => 'test-chat'], 'como' => ['chat_id' => 'test-chat'],
    ]]);

    app(TelegramBotService::class)->sendMessage('test-chat', 'text');

    expect(TelegramOutboundOperation::query()->sole()->district)->toBeNull();
});

it('associates an event-driven send with its business object', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 24]])]);
    $request = (new DayOffRequest)->forceFill(['id' => 42]);

    (new ProbeTaskTelegramNotificationService)->send($request);

    $row = TelegramOutboundOperation::query()->sole();
    expect($row->notification_type)->toBe('task')
        ->and($row->source_type)->toBe('DayOffRequest')
        ->and($row->source_id)->toBe('42');
});

it('keeps a single logical record for a keyed retry', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 25]], 200)]);
    $monitor = app(TelegramOutboundMonitor::class);

    foreach (range(1, 2) as $attempt) {
        $monitor->withContext(['operation_key' => 'morning:2026-10-21:lodi', 'notification_type' => 'morning_digest'],
            fn () => app(TelegramBotService::class)->sendMessage('test-chat', 'text'));
    }

    expect(TelegramOutboundOperation::query()->count())->toBe(1)
        ->and(TelegramOutboundOperation::query()->sole()->attempts)->toBe(2);
});

it('records an expected event before HTTP and reuses it for the actual attempt', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 25]])]);
    $monitor = app(TelegramOutboundMonitor::class);
    $context = [
        'operation_key' => 'day_off:created:42', 'notification_type' => 'day_off',
        'source_type' => 'DayOffRequest', 'source_id' => '42',
        'bot' => 'work', 'chat_id' => 'test-chat',
    ];

    $monitor->expect($context);
    $monitor->expect($context);
    expect(TelegramOutboundOperation::query()->sole()->status)->toBe('expected')
        ->and(TelegramOutboundOperation::query()->sole()->attempts)->toBe(0);

    $monitor->withContext($context, fn () => app(TelegramBotService::class)->sendMessage('test-chat', 'private'));

    expect(TelegramOutboundOperation::query()->count())->toBe(1)
        ->and(TelegramOutboundOperation::query()->sole()->status)->toBe('sent')
        ->and(TelegramOutboundOperation::query()->sole()->attempts)->toBe(1);
});

it('never blocks sending if the journal is unavailable', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 25]])]);
    Schema::dropIfExists('telegram_outbound_operations');

    $monitor = app(TelegramOutboundMonitor::class);
    $monitor->expect(['operation_key' => 'access:pending:42', 'notification_type' => 'user_access']);

    expect($monitor->withContext(['operation_key' => 'access:pending:42'],
        fn () => app(TelegramBotService::class)->sendMessage('test-chat', 'private')))->toBe(25);
});

it('marks a lost HTTP response as uncertain rather than failed or sent', function (): void {
    Http::fake(['api.telegram.org/*' => Http::failedConnection()]);

    expect(fn () => app(TelegramBotService::class)->sendMessage('test-chat', 'text'))
        ->toThrow(ConnectionException::class);

    expect(TelegramOutboundOperation::query()->sole()->status)->toBe('uncertain');
});

it('does not call a successful HTTP response with no receipt delivered', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    expect(app(TelegramBotService::class)->sendMessage('test-chat', 'text'))->toBeNull()
        ->and(TelegramOutboundOperation::query()->sole()->status)->toBe('uncertain');
});

it('records media groups and edits while ignoring non-notification Telegram methods', function (): void {
    Http::fake([
        'api.telegram.org/*/sendMediaGroup' => Http::response(['ok' => true, 'result' => [['message_id' => 30]]]),
        'api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => true]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
    ]);

    $base = 'https://api.telegram.org/botwork-test-token/';
    Http::post($base.'sendMediaGroup', ['chat_id' => 'test-chat', 'media' => []]);
    Http::post($base.'editMessageText', ['chat_id' => 'test-chat', 'message_id' => 30, 'text' => 'changed']);
    Http::post($base.'getUpdates', ['limit' => 1]);

    expect(TelegramOutboundOperation::query()->count())->toBe(2)
        ->and(TelegramOutboundOperation::query()->where('method', 'sendMediaGroup')->sole()->status)->toBe('sent')
        ->and(TelegramOutboundOperation::query()->where('method', 'editMessageText')->sole()->status)->toBe('edited');
});

class ProbeTaskTelegramNotificationService
{
    public function send(DayOffRequest $request): void
    {
        app(TelegramBotService::class)->sendMessage('test-chat', 'text');
    }
}

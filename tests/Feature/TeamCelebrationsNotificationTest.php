<?php

use App\Models\TelegramOutboundOperation;
use App\Services\Calendar\TeamCelebrationService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 08:45:00', 'Europe/Rome'));
    config([
        'services.telegram.bot_token' => 'work-test-token',
        'services.telegram.chat_id_celebrations' => 'celebrations-test-chat',
        'services.telegram.thread_id_celebrations' => '7',
    ]);
    Schema::dropIfExists('telegram_outbound_operations');
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
    Schema::dropIfExists('telegram_outbound_operations');
});

function teamCelebrationTestEvent(array $overrides = []): array
{
    return array_merge([
        'kind' => 'birthday',
        'operation_key' => 'team_celebration:birthday:42:2026-10-09:routehash',
        'source_type' => 'User',
        'source_id' => '42',
        'text' => '🎂 С днём рождения, <b>Сотрудник</b>!',
        'photo_path' => null,
    ], $overrides);
}

function teamCelebrationServiceReturning(array $events, string $expectedDate = '2026-10-09', int $times = 1): void
{
    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldReceive('eventsForDate')
        ->times($times)
        ->with(Mockery::on(fn ($date) => $date->toDateString() === $expectedDate
            && $date->getTimezone()->getName() === 'Europe/Rome'))
        ->andReturn(collect($events));
    app()->instance(TeamCelebrationService::class, $service);
}

it('prints a dry-run preview without sending or reserving an operation', function () {
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('С днём рождения', 'Фото: нет')
        ->not->toContain('42', 'celebrations-test-chat', 'work-test-token')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('fails closed when the dedicated celebration destination is missing', function () {
    config(['services.telegram.chat_id_celebrations' => null]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);

    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends through the Work Bot route once and reuses the journal key on rerun', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 71]], 200)]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], times: 2);

    $firstResult = Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']);
    $firstOutput = Artisan::output();
    $journalState = TelegramOutboundOperation::query()->get(['status', 'error_code', 'error_reason', 'attempts'])->toJson();

    expect($firstResult)->toBe(0, $firstOutput.' '.$journalState)
        ->and(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && $request->data()['chat_id'] === 'celebrations-test-chat'
        && $request->data()['message_thread_id'] === 7
        && str_contains($request->data()['text'], 'С днём рождения'));

    expect(TelegramOutboundOperation::query()->sole())
        ->toMatchArray(['notification_type' => 'team_celebration', 'bot' => 'work', 'status' => 'sent', 'telegram_message_id' => 71])
        ->and(TelegramOutboundOperation::query()->sole()->attempts)->toBe(1);
});

it('does not resend an uncertain Telegram result on a repeated command', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::failedConnection()]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], times: 2);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);

    Http::assertSentCount(1);
    expect(TelegramOutboundOperation::query()->sole()->status)->toBe('uncertain');
});

it('sends a local card as one sendPhoto operation without a text fallback', function () {
    Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 72]], 200)]);
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    teamCelebrationServiceReturning([teamCelebrationTestEvent(['photo_path' => $path])]);

    $result = Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']);
    $output = Artisan::output();
    $journalState = TelegramOutboundOperation::query()->get(['status', 'error_code', 'error_reason', 'attempts'])->toJson();

    expect($result)->toBe(0, $output.' '.$journalState);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto')
        && $request->hasFile('photo'));
    expect(TelegramOutboundOperation::query()->sole())
        ->toMatchArray([
            'method' => 'sendPhoto',
            'status' => 'sent',
            'chat_id' => 'celebrations-test-chat',
            'message_thread_id' => '7',
            'telegram_message_id' => 72,
        ]);
    unlink($path);
});

it('does not send a text fallback after a photo attempt has an uncertain result', function () {
    Http::fake(fn (Request $request) => Http::failedConnection());
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    teamCelebrationServiceReturning([teamCelebrationTestEvent(['photo_path' => $path])]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto'));
    expect(TelegramOutboundOperation::query()->sole())
        ->toMatchArray(['method' => 'sendPhoto', 'status' => 'uncertain']);

    unlink($path);
});

it('refuses to send historical events outside the current Rome date', function () {
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    teamCelebrationServiceReturning([teamCelebrationTestEvent()], '2026-10-08');
    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-08']))->toBe(1)
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('schedules the separate celebration command at nine in Europe Rome without overlap', function () {
    expect(base_path())->toBe(getcwd());
    expect(app(Kernel::class)->all())
        ->toHaveKey('calendar:notify-celebrations');

    $event = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'calendar:notify-celebrations'));

    expect($event)->not->toBeNull()
        ->and($event->timezone)->toBe('Europe/Rome')
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

<?php

use App\Models\TelegramOutboundOperation;
use App\Services\Calendar\TeamCelebrationService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
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

function teamCelebrationServiceReturning(array $events, string|array $expectedDate = '2026-10-09', int $times = 1): void
{
    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldReceive('eventsForDate')
        ->times($times)
        ->with(Mockery::on(fn ($date) => (is_array($expectedDate)
            ? in_array($date->toDateString(), $expectedDate, true)
            : $date->toDateString() === $expectedDate)
            && $date->getTimezone()->getName() === 'Europe/Rome'))
        ->andReturn(collect($events));
    $service->shouldReceive('formatDigest')
        ->times($times)
        ->with(Mockery::any(), Mockery::any())
        ->andReturnUsing(function (CarbonImmutable $date, Collection $filteredEvents): ?array {
            if ($filteredEvents->isEmpty()) {
                return null;
            }

            $sections = $filteredEvents->pluck('text')->map(fn ($text) => '• '.$text)->implode("\n");
            $text = '📅 <b>События на '.$date->format('d.m.Y')."</b>\n\n🎂 <b>Дни рождения</b>\n".$sections;
            $photoPath = $filteredEvents->firstWhere('kind', 'birthday')['photo_path'] ?? null;

            return ['text' => $text, 'photo_path' => $photoPath, 'over_limit' => false, 'character_count' => mb_strlen(strip_tags($text))];
        });
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

it('does not create an outbound message for an empty date', function () {
    teamCelebrationServiceReturning([]);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0)
        ->and(Artisan::output())->toContain('На сегодня событий нет')
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
        ->toMatchArray(['notification_type' => 'calendar', 'bot' => 'work', 'status' => 'sent', 'telegram_message_id' => 71])
        ->and(TelegramOutboundOperation::query()->sole()->operation_key)->toStartWith('calendar:celebrations:2026-10-09:')
        ->and(TelegramOutboundOperation::query()->sole()->attempts)->toBe(1);
});

it('sends multiple same-day events in one Telegram request', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 74]], 200)]);
    teamCelebrationServiceReturning([
        teamCelebrationTestEvent(),
        teamCelebrationTestEvent([
            'operation_key' => 'team_celebration:birthday:43:2026-10-09:routehash',
            'source_id' => '43',
            'text' => '🏆 <b>Вторая запись</b>',
        ]),
    ]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], 'С днём рождения')
        && str_contains($request->data()['text'], 'Вторая запись'));
    expect(TelegramOutboundOperation::query()->count())->toBe(1);
});

it('does not resend an uncertain Telegram result on a repeated command', function () {
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::failedConnection()]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], times: 2);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);

    Http::assertSentCount(1);
    expect(TelegramOutboundOperation::query()->sole()->status)->toBe('uncertain');
});

it('suppresses a legacy personal event already handled before digest cutover', function (string $status) {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'team_celebration:birthday:42:2026-10-09:routehash',
        'notification_type' => 'team_celebration',
        'method' => 'sendMessage',
        'bot' => 'work',
        'chat_id' => 'celebrations-test-chat',
        'message_thread_id' => '7',
        'source_type' => 'User',
        'source_id' => '42',
        'scheduled_for' => Carbon::parse('2026-10-09 09:00:00', 'Europe/Rome'),
        'status' => $status,
        'telegram_message_id' => $status === 'sent' ? 70 : null,
        'attempts' => 1,
    ]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0)
        ->and(Artisan::output())->toContain('уже учтены')
        ->and(TelegramOutboundOperation::query()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['expected', 'pending', 'sent', 'edited', 'uncertain']);

it('keeps definitively failed legacy personal events eligible for the new digest', function () {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'team_celebration:birthday:42:2026-10-09:routehash',
        'notification_type' => 'team_celebration',
        'method' => 'sendMessage',
        'bot' => 'work',
        'chat_id' => 'celebrations-test-chat',
        'message_thread_id' => '7',
        'source_type' => 'User',
        'source_id' => '42',
        'status' => 'failed',
        'attempts' => 1,
    ]);
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 73]], 200)]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);
    Http::assertSentCount(1);
    expect(TelegramOutboundOperation::query()->where('notification_type', 'calendar')->sole()->status)->toBe('sent');
});

it('previews seven selected days only in dry-run without journal writes or Telegram calls', function () {
    $dates = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'];
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], $dates, 7);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09', '--days' => 7, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('09.10.2026', '15.10.2026', 'События на 10.10.2026')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('uses Europe Rome when selecting the default preview date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], '2026-10-10');
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('10.10.2026');
    Http::assertNothingSent();
});

it('rejects a seven-day window for real delivery', function () {
    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldNotReceive('eventsForDate');
    app()->instance(TeamCelebrationService::class, $service);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09', '--days' => 7]))->toBe(1)
        ->and(Artisan::output())->toContain('только для предварительного просмотра');
    Http::assertNothingSent();
});

it('refuses an oversized digest before reserving or sending it', function () {
    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldReceive('eventsForDate')->once()->andReturn(collect([teamCelebrationTestEvent()]));
    $service->shouldReceive('formatDigest')->once()->andReturn([
        'text' => str_repeat('x', 4097),
        'photo_path' => null,
        'over_limit' => true,
        'character_count' => 4097,
    ]);
    app()->instance(TeamCelebrationService::class, $service);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(Artisan::output())->toContain('превышает лимит Telegram')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
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

    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldNotReceive('eventsForDate');
    app()->instance(TeamCelebrationService::class, $service);
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

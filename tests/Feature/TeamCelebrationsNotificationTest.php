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
    Carbon::setTestNow(Carbon::parse('2026-10-08 08:45:00', 'Europe/Rome'));
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
        'operation_key' => 'team_celebration:birthday:42:2026-10-08:routehash',
        'source_type' => 'User',
        'source_id' => '42',
        'text' => '🎂 С днём рождения, <b>Сотрудник</b>!',
        'photo_path' => null,
    ], $overrides);
}

function teamCelebrationServiceReturning(array $events, string|array $expectedDate = '2026-10-09', int $times = 1): void
{
    $summaryDates = is_array($expectedDate) ? $expectedDate : [$expectedDate];
    $allowedDates = collect($summaryDates)
        ->flatMap(fn (string $date): array => [
            CarbonImmutable::parse($date, 'Europe/Rome')->toDateString(),
            CarbonImmutable::parse($date, 'Europe/Rome')->subDay()->toDateString(),
        ])
        ->unique()
        ->all();

    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldReceive('eventsForDate')
        ->times($times * 2)
        ->with(Mockery::on(fn ($date) => in_array($date->toDateString(), $allowedDates, true)
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
            $text = '📅 <b>События на '.$date->format('d.m.Y')."</b>\n\n".$sections;

            return ['text' => $text, 'photo_path' => null, 'over_limit' => false, 'character_count' => mb_strlen(strip_tags($text))];
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
        ->and(Artisan::output())->toContain('08.10.2026', 'С днём рождения', 'Фото: нет')
        ->not->toContain('42', 'celebrations-test-chat', 'work-test-token')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('previews the explicit no-events message without sending anything', function () {
    teamCelebrationServiceReturning([], '2026-10-09');
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Дней рождения нет.', '📅 <b>События на 09.10.2026</b>', 'ℹ️ На завтра событий нет.')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('previews a dated no-events digest for an arbitrary date', function () {
    teamCelebrationServiceReturning([], '2026-10-10');

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-10', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('10.10.2026', 'Дней рождения нет.', 'ℹ️ На завтра событий нет.')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('sends one dated no-events notification in the dedicated topic and deduplicates reruns', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-06 08:45:00', 'Europe/Rome'));
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 71]], 200)]);
    teamCelebrationServiceReturning([], '2026-11-07', times: 2);

    expect(Artisan::call('calendar:notify-celebrations'))->toBe(0)
        ->and(Artisan::call('calendar:notify-celebrations'))->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && $request->data()['chat_id'] === 'celebrations-test-chat'
        && $request->data()['message_thread_id'] === 7
        && $request->data()['text'] === "📅 <b>События на 07.11.2026</b>\n\nℹ️ На завтра событий нет.");
    expect(TelegramOutboundOperation::query()->sole())
        ->toMatchArray([
            'status' => 'sent',
            'source_type' => 'TeamCelebrationNonBirthdayDigest',
            'telegram_message_id' => 71,
        ]);
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

it('fails closed when a birthday card for the month is missing', function () {
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(Artisan::output())->toContain('Нет читаемой открытки', 'поздравления не отправлены')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires a positive forum topic id instead of falling back to the forum root', function () {
    config(['services.telegram.thread_id_celebrations' => '0']);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
        ->and(Artisan::output())->toContain('Не настроен отдельный форумный топик')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends today birthday separately from tomorrow events and reuses journal keys on rerun', function () {
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    $messageId = 70;
    Http::fake(function (Request $request) use (&$messageId) {
        return Http::response(['ok' => true, 'result' => ['message_id' => ++$messageId]], 200);
    });
    teamCelebrationServiceReturning([
        teamCelebrationTestEvent(['photo_path' => $path]),
        teamCelebrationTestEvent([
            'kind' => 'work_anniversary',
            'operation_key' => 'team_celebration:work_anniversary:43:2026-10-09:routehash',
            'source_id' => '43',
            'text' => '<b>Сотрудник</b> отмечает 5 лет в TRIS.',
            'photo_path' => null,
        ]),
    ], times: 2);

    try {
        $firstResult = Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']);
        $firstOutput = Artisan::output();
        $journalState = TelegramOutboundOperation::query()->get(['status', 'error_code', 'error_reason', 'attempts'])->toJson();

        expect($firstResult)->toBe(0, $firstOutput.' '.$journalState)
            ->and(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto')
            && $request->hasFile('photo'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && $request->data()['chat_id'] === 'celebrations-test-chat'
            && $request->data()['message_thread_id'] === 7
            && str_contains($request->data()['text'], 'События на 09.10.2026')
            && str_contains($request->data()['text'], 'отмечает 5 лет')
            && ! str_contains($request->data()['text'], 'С днём рождения'));

        expect(TelegramOutboundOperation::query()->count())->toBe(2)
            ->and(TelegramOutboundOperation::query()->where('source_type', 'User')->sole())
            ->toMatchArray([
                'notification_type' => 'team_celebration',
                'bot' => 'work',
                'chat_id' => 'celebrations-test-chat',
                'message_thread_id' => '7',
                'status' => 'sent',
                'telegram_message_id' => 71,
            ])
            ->and(TelegramOutboundOperation::query()->where('source_type', 'User')->sole()->operation_key)
            ->toBe('team_celebration:birthday:42:2026-10-08:routehash')
            ->and(TelegramOutboundOperation::query()->where('source_type', 'TeamCelebrationNonBirthdayDigest')->sole())
            ->toMatchArray(['status' => 'sent', 'telegram_message_id' => 72]);
    } finally {
        @unlink($path);
    }
});

it('sends birthdays separately and still reports no events tomorrow', function () {
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    $messageId = 73;
    Http::fake(function (Request $request) use (&$messageId) {
        return Http::response(['ok' => true, 'result' => ['message_id' => ++$messageId]], 200);
    });
    teamCelebrationServiceReturning([
        teamCelebrationTestEvent(['photo_path' => $path]),
        teamCelebrationTestEvent([
            'operation_key' => 'team_celebration:birthday:43:2026-10-08:routehash',
            'source_id' => '43',
            'text' => '🎂 С днём рождения, <b>Вторая запись</b>!',
            'photo_path' => $path,
        ]),
    ]);

    try {
        expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto')
            && $request->hasFile('photo'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request->data()['text'], 'На завтра событий нет.'));
        expect(TelegramOutboundOperation::query()->where('source_type', 'User')->count())->toBe(2)
            ->and(TelegramOutboundOperation::query()->where('source_type', 'User')->pluck('message_thread_id')->unique()->all())->toBe(['7']);
    } finally {
        @unlink($path);
    }
});

it('does not resend an uncertain Telegram result on a repeated command', function () {
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    Http::fake(fn (Request $request) => Http::failedConnection());
    teamCelebrationServiceReturning([teamCelebrationTestEvent(['photo_path' => $path])], times: 2);

    try {
        expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(1)
            ->and(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendPhoto'));
        expect(TelegramOutboundOperation::query()->where('notification_type', 'team_celebration')->sole()->status)->toBe('uncertain')
            ->and(TelegramOutboundOperation::query()->where('source_type', 'TeamCelebrationNonBirthdayDigest')->sole()->status)->toBe('uncertain');
    } finally {
        @unlink($path);
    }
});

it('suppresses a legacy personal event already handled before digest cutover', function (string $status) {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'team_celebration:birthday:42:2026-10-08:routehash',
        'notification_type' => 'team_celebration',
        'method' => 'sendMessage',
        'bot' => 'work',
        'chat_id' => 'celebrations-test-chat',
        'message_thread_id' => '7',
        'source_type' => 'User',
        'source_id' => '42',
        'scheduled_for' => Carbon::parse('2026-10-08 09:00:00', 'Europe/Rome'),
        'status' => $status,
        'telegram_message_id' => $status === 'sent' ? 70 : null,
        'attempts' => 1,
    ]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent()]);
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 71]], 200)]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0)
        ->and(Artisan::output())->toContain('Сводка событий отправлена')
        ->and(TelegramOutboundOperation::query()->count())->toBe(2);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request->data()['text'], 'На завтра событий нет.'));
})->with(['expected', 'pending', 'sent', 'edited', 'uncertain']);

it('keeps definitively failed legacy birthday events eligible for a new message', function () {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'team_celebration:birthday:42:2026-10-08:routehash',
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
    $path = tempnam(sys_get_temp_dir(), 'tris-card-');
    file_put_contents($path, 'fixture-image-bytes');
    Http::fake([
        'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 73]], 200),
        'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 74]], 200),
    ]);
    teamCelebrationServiceReturning([teamCelebrationTestEvent(['photo_path' => $path])]);

    try {
        expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);
        Http::assertSentCount(2);
        expect(TelegramOutboundOperation::query()->where('notification_type', 'team_celebration')->sole()->status)->toBe('sent');
    } finally {
        @unlink($path);
    }
});

it('does not repeat a birthday that was already included in the old grouped digest', function () {
    $route = 'celebrations-test-chat|7';
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'calendar:celebrations:2026-10-08:'.substr(hash('sha256', $route), 0, 20),
        'notification_type' => 'calendar',
        'method' => 'sendMessage',
        'bot' => 'work',
        'chat_id' => 'celebrations-test-chat',
        'message_thread_id' => '7',
        'source_type' => 'TeamCelebrationDigest',
        'source_id' => '2026-10-08',
        'scheduled_for' => Carbon::parse('2026-10-08 06:05:00', 'Europe/Rome'),
        'status' => 'sent',
        'telegram_message_id' => 69,
        'attempts' => 1,
    ]);
    Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 74]], 200)]);
    teamCelebrationServiceReturning([
        teamCelebrationTestEvent(),
        teamCelebrationTestEvent([
            'kind' => 'work_anniversary',
            'operation_key' => 'team_celebration:work_anniversary:43:2026-10-09:routehash',
            'source_id' => '43',
            'text' => '<b>Сотрудник</b> отмечает 5 лет в TRIS.',
        ]),
    ]);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09']))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request->data()['text'], 'отмечает 5 лет')
        && ! str_contains($request->data()['text'], 'С днём рождения'));
    expect(TelegramOutboundOperation::query()->where('source_type', 'TeamCelebrationNonBirthdayDigest')->sole()->status)->toBe('sent')
        ->and(TelegramOutboundOperation::query()->count())->toBe(2);
});

it('previews seven selected days only in dry-run without journal writes or Telegram calls', function () {
    $dates = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'];
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], $dates, 7);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--date' => '2026-10-09', '--days' => 7, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('09.10.2026', '15.10.2026', 'события на 10.10.2026')
        ->and(TelegramOutboundOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('uses Europe Rome when selecting the default preview date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
    teamCelebrationServiceReturning([teamCelebrationTestEvent()], '2026-10-11');
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    $bot->shouldNotReceive('sendPhoto');
    app()->instance(TelegramBotService::class, $bot);

    expect(Artisan::call('calendar:notify-celebrations', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('11.10.2026');
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

it('refuses an oversized non-birthday digest before reserving or sending it', function () {
    $service = Mockery::mock(TeamCelebrationService::class);
    $service->shouldReceive('eventsForDate')->twice()->andReturn(collect([teamCelebrationTestEvent([
        'kind' => 'work_anniversary',
        'operation_key' => 'team_celebration:work_anniversary:42:2026-10-09:routehash',
    ])]));
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

it('refuses to send events outside tomorrow Rome date', function () {
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

it('schedules the celebration command at 06:05 after the 06:00 calendar digest in Europe Rome', function () {
    expect(base_path())->toBe(getcwd());
    expect(app(Kernel::class)->all())
        ->toHaveKey('calendar:notify-celebrations');

    $event = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'calendar:notify-celebrations'));
    $calendarEvent = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'calendar:notify-tomorrow'));

    expect($event)->not->toBeNull()
        ->and($event->timezone)->toBe('Europe/Rome')
        ->and($event->expression)->toBe('5 6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($calendarEvent)->not->toBeNull()
        ->and($calendarEvent->timezone)->toBe('Europe/Rome')
        ->and($calendarEvent->expression)->toBe('0 6 * * *');
});

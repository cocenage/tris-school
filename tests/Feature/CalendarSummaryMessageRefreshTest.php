<?php

use App\Jobs\RefreshCalendarSummaryMessage;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\TelegramOutboundOperation;
use App\Models\User;
use App\Models\VacationRequestDay;
use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\CalendarSummaryRefreshService;
use App\Services\Calendar\CalendarSummaryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'app.timezone' => 'Europe/Rome',
        'services.telegram.bot_token' => 'test-calendar-token',
        'services.telegram.chat_id_calendar' => 'calendar-test-chat',
        'services.telegram.thread_id_calendar' => 'calendar-test-thread',
        'services.telegram.rich_messages_enabled' => false,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-05 09:48:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 09:48:00', 'Europe/Rome'));
    Http::preventStrayRequests();

    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    (require database_path('migrations/2026_11_01_000000_create_telegram_outbound_operation_events_table.php'))->up();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->date('work_started_at')->nullable();
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });
    Schema::create('day_off_requests', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('day_off_request_days', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('day_off_request_id');
        $table->foreignId('user_id');
        $table->date('date');
        $table->string('status');
        $table->text('admin_comment')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->foreignId('reviewed_by')->nullable();
        $table->timestamps();
    });
    Schema::create('vacation_requests', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id');
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->unsignedInteger('days_count')->nullable();
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('vacation_request_days', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('vacation_request_id');
        $table->foreignId('user_id');
        $table->date('date');
        $table->string('status');
        $table->text('admin_comment')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->foreignId('reviewed_by')->nullable();
        $table->timestamps();
    });
    (require database_path('migrations/2026_11_04_160000_create_employee_work_schedule_versions_table.php'))->up();
    (require database_path('migrations/2026_11_04_160001_create_employee_calendar_adjustments_table.php'))->up();
    (require database_path('migrations/2026_11_06_000000_add_last_sent_content_hash_to_telegram_outbound_operations.php'))->up();

    $events = Mockery::mock(CalendarEventsService::class);
    $events->shouldReceive('getEventsForDay')->byDefault()->andReturn(collect());
    app()->instance(CalendarEventsService::class, $events);
});

afterEach(function (): void {
    foreach ([
        'employee_calendar_adjustments',
        'employee_work_schedule_versions',
        'vacation_request_days',
        'vacation_requests',
        'day_off_request_days',
        'day_off_requests',
        'users',
        'telegram_outbound_operation_events',
        'telegram_outbound_operations',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function calendarRefreshEmployee(string $name = 'Тестовый клинер', array $weekendDays = []): User
{
    $employee = User::query()->create([
        'name' => $name,
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'work_started_at' => '2020-01-01',
        'weekend_days' => $weekendDays,
    ]);

    $employee->workScheduleVersions()->create([
        'effective_from' => '2020-01-01',
        'version_type' => 'schedule_change',
        'employment_start_date' => '2020-01-01',
        'weekend_days' => $weekendDays,
        'new_values' => ['employment_start_date' => '2020-01-01', 'weekend_days' => $weekendDays],
        'reason' => 'Test fixture',
    ]);

    return $employee;
}

function calendarRefreshRequestDay(User $employee, string $kind, string $date, string $status): int
{
    $requestId = DB::table($kind.'_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'partially_approved',
        'reason' => 'Не предназначено для сводки',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dayId = DB::table($kind.'_request_days')->insertGetId([
        $kind.'_request_id' => $requestId,
        'user_id' => $employee->id,
        'date' => $date,
        'status' => $status,
        'admin_comment' => 'Личный комментарий не журналируется',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $dayId;
}

function calendarRefreshSentOperation(
    string $date,
    ?string $hash,
    int $messageId,
    ?string $chatId = null,
    string $type = 'calendar',
    ?string $operationKey = null,
): TelegramOutboundOperation {
    return TelegramOutboundOperation::query()->create([
        'operation_key' => $operationKey ?? 'calendar:tomorrow:'.$date,
        'notification_type' => $type,
        'method' => 'sendMessage',
        'bot' => 'work',
        'chat_id' => $chatId ?? config('services.telegram.chat_id_calendar'),
        'message_thread_id' => config('services.telegram.thread_id_calendar'),
        'source_type' => 'CalendarDate',
        'source_id' => $date,
        'status' => 'sent',
        'telegram_message_id' => $messageId,
        'last_sent_content_hash' => $hash,
        'attempts' => 1,
        'attempted_at' => now(),
        'finished_at' => now(),
    ]);
}

function calendarRefreshTelegramResponse(Request $request)
{
    $method = basename((string) parse_url($request->url(), PHP_URL_PATH));

    return Http::response($method === 'sendMessage'
        ? ['ok' => true, 'result' => ['message_id' => 741]]
        : ['ok' => true, 'result' => true]);
}

it('stores the Telegram message id and hash only after the initial send is accepted', function (): void {
    calendarRefreshEmployee();
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));

    expect(Artisan::call('calendar:notify-tomorrow'))->toBe(0);

    $operation = TelegramOutboundOperation::query()
        ->where('operation_key', 'calendar:tomorrow:2026-11-06')
        ->sole();
    $payload = Http::recorded()->first()[0]->data();

    expect($operation->telegram_message_id)->toBe(741)
        ->and($operation->last_sent_content_hash)->toBe(hash('sha256', $payload['text']))
        ->and($operation->chat_id)->toBe('calendar-test-chat')
        ->and($operation->source_id)->toBe('2026-11-06')
        ->and(json_encode($operation->toArray()))->not->toContain($payload['text'], 'test-calendar-token', 'Личный комментарий');
});

it('edits the existing message when an approved day off is added or cancelled', function (): void {
    $employee = calendarRefreshEmployee();
    $dayId = calendarRefreshRequestDay($employee, 'day_off', '2026-11-06', 'pending');
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));
    Artisan::call('calendar:notify-tomorrow');
    $operation = TelegramOutboundOperation::query()->where('source_id', '2026-11-06')->sole();
    $firstHash = $operation->last_sent_content_hash;

    DayOffRequestDay::query()->findOrFail($dayId)->update(['status' => 'approved']);
    $approvedHash = $operation->fresh()->last_sent_content_hash;
    DayOffRequestDay::query()->findOrFail($dayId)->update(['status' => 'rejected']);
    $restored = $operation->fresh();

    expect($restored->telegram_message_id)->toBe(741)
        ->and($approvedHash)->not->toBe($firstHash)
        ->and($restored->last_sent_content_hash)->toBe($firstHash)
        ->and($restored->attempts)->toBe(3)
        ->and(Http::recorded())->toHaveCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
});

it('uses only approved dates from partially approved vacations', function (): void {
    $employee = calendarRefreshEmployee();
    $approvedDay = calendarRefreshRequestDay($employee, 'vacation', '2026-11-06', 'approved');
    $pendingDay = calendarRefreshRequestDay($employee, 'vacation', '2026-11-07', 'pending');

    $summary = app(CalendarSummaryService::class)->build('2026-11-06');

    expect($summary['workers']['by_role']['cleaner']['available_count'])->toBe(0)
        ->and(collect($summary['workers']['by_role']['cleaner']['absent'])->pluck('reason')->all())
        ->toBe(['vacation'])
        ->and(VacationRequestDay::query()->findOrFail($approvedDay)->status)->toBe('approved')
        ->and(VacationRequestDay::query()->findOrFail($pendingDay)->status)->toBe('pending');
});

it('does not edit when repeated reconciliation sees the same content', function (): void {
    calendarRefreshEmployee();
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));
    Artisan::call('calendar:notify-tomorrow');
    $operation = TelegramOutboundOperation::query()->where('source_id', '2026-11-06')->sole();
    $countAfterSend = Http::recorded()->count();

    app(CalendarSummaryRefreshService::class)->refresh('2026-11-06');
    app(CalendarSummaryRefreshService::class)->refresh('2026-11-06');

    expect(Http::recorded())->toHaveCount($countAfterSend)
        ->and($operation->fresh()->attempts)->toBe(1);
});

it('does not run a second refresh while the same date is locked', function (): void {
    calendarRefreshEmployee();
    calendarRefreshSentOperation('2026-11-06', str_repeat('a', 64), 741);
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));
    $lock = Cache::lock('telegram-calendar-summary:'.hash('sha256', 'calendar:tomorrow:2026-11-06'), 120);

    expect($lock->get())->toBeTrue()
        ->and(app(CalendarSummaryRefreshService::class)->refresh('2026-11-06'))->toBeFalse()
        ->and(Http::recorded())->toBeEmpty();

    $lock->release();
});

it('safely initializes a missing legacy hash through Telegram without changing an identical message', function (): void {
    calendarRefreshEmployee();
    $operation = calendarRefreshSentOperation('2026-11-06', null, 741);
    Http::fake(fn () => Http::response([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: message is not modified',
    ], 400));

    expect(app(CalendarSummaryRefreshService::class)->refresh('2026-11-06'))->toBeTrue()
        ->and($operation->fresh()->status)->toBe('edited')
        ->and($operation->fresh()->telegram_message_id)->toBe(741)
        ->and($operation->fresh()->last_sent_content_hash)->toHaveLength(64)
        ->and(Http::recorded())->toHaveCount(1);
});

it('keeps the original message and successful hash after a failed edit, then retries safely', function (): void {
    $employee = calendarRefreshEmployee();
    $dayId = calendarRefreshRequestDay($employee, 'day_off', '2026-11-06', 'pending');
    Http::fakeSequence()
        ->push(['ok' => true, 'result' => ['message_id' => 741]])
        ->push(['ok' => false, 'error_code' => 503, 'description' => 'temporary failure'], 503)
        ->push(['ok' => true, 'result' => true]);
    Artisan::call('calendar:notify-tomorrow');
    $operation = TelegramOutboundOperation::query()->where('source_id', '2026-11-06')->sole();
    $previousHash = $operation->last_sent_content_hash;

    DayOffRequestDay::query()->findOrFail($dayId)->update(['status' => 'approved']);
    $afterFailure = $operation->fresh();
    app(CalendarSummaryRefreshService::class)->refresh('2026-11-06');
    $afterRetry = $operation->fresh();

    expect($afterFailure->status)->toBe('failed')
        ->and($afterFailure->telegram_message_id)->toBe(741)
        ->and($afterFailure->last_sent_content_hash)->toBe($previousHash)
        ->and($afterRetry->status)->toBe('edited')
        ->and($afterRetry->telegram_message_id)->toBe(741)
        ->and($afterRetry->last_sent_content_hash)->not->toBe($previousHash)
        ->and($afterRetry->attempts)->toBe(3)
        ->and(Http::recorded())->toHaveCount(3);
});

it('reconciles only existing today and tomorrow calendar messages on the Rome date', function (): void {
    calendarRefreshEmployee();
    calendarRefreshSentOperation('2026-11-05', str_repeat('a', 64), 701);
    calendarRefreshSentOperation('2026-11-06', str_repeat('b', 64), 702);
    calendarRefreshSentOperation('2026-11-06', str_repeat('c', 64), 703, 'other-chat', 'morning_digest', 'morning_digest:2026-11-06');
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));

    expect(Artisan::call('calendar:summaries-reconcile'))->toBe(0)
        ->and(Http::recorded())->toHaveCount(2)
        ->and(TelegramOutboundOperation::query()->count())->toBe(3)
        ->and(TelegramOutboundOperation::query()->where('notification_type', 'morning_digest')->sole()->telegram_message_id)->toBe(703);
});

it('does not create a message when reconciliation has no previously sent operation', function (): void {
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));

    expect(Artisan::call('calendar:summaries-reconcile'))->toBe(0)
        ->and(TelegramOutboundOperation::query()->count())->toBe(0)
        ->and(Http::recorded())->toBeEmpty();
});

it('registers the five-minute reconciliation in the Rome timezone without overlap', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'calendar:summaries-reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->timezone)->toBe('Europe/Rome')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('uses Rome civil dates for tomorrow across daylight-saving transitions', function (): void {
    Http::fake(fn (Request $request) => calendarRefreshTelegramResponse($request));

    foreach ([
        '2026-03-28 23:30:00' => '2026-03-29',
        '2026-10-24 23:30:00' => '2026-10-25',
    ] as $instant => $expectedDate) {
        Carbon::setTestNow(Carbon::parse($instant, 'Europe/Rome'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'Europe/Rome'));
        calendarRefreshSentOperation($expectedDate, str_repeat('f', 64), $expectedDate === '2026-03-29' ? 801 : 802);

        expect(Artisan::call('calendar:summaries-reconcile'))->toBe(0)
            ->and(Http::recorded())->toHaveCount($expectedDate === '2026-03-29' ? 1 : 2);
    }
});

it('dispatches a calendar refresh only after the source transaction commits', function (): void {
    $employee = calendarRefreshEmployee();
    $dayId = calendarRefreshRequestDay($employee, 'day_off', '2026-11-06', 'pending');
    calendarRefreshSentOperation('2026-11-06', str_repeat('a', 64), 741);
    Queue::fake();

    DB::beginTransaction();
    DayOffRequestDay::query()->findOrFail($dayId)->update(['status' => 'approved']);
    Queue::assertNothingPushed();
    DB::commit();

    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-06');
});

it('refreshes both dates when a request day moves from one date to another', function (): void {
    $employee = calendarRefreshEmployee();
    $dayId = calendarRefreshRequestDay($employee, 'day_off', '2026-11-06', 'approved');
    calendarRefreshSentOperation('2026-11-06', str_repeat('a', 64), 741);
    calendarRefreshSentOperation('2026-11-07', str_repeat('b', 64), 742);
    Queue::fake();

    DayOffRequestDay::query()->findOrFail($dayId)->update(['date' => '2026-11-07']);

    Queue::assertPushed(RefreshCalendarSummaryMessage::class, 2);
    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-06');
    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-07');
});

it('refreshes today and tomorrow when staff role or activity changes', function (): void {
    $employee = calendarRefreshEmployee();
    calendarRefreshSentOperation('2026-11-05', str_repeat('a', 64), 741);
    calendarRefreshSentOperation('2026-11-06', str_repeat('b', 64), 742);
    Queue::fake();

    $employee->update(['role' => 'supervisor', 'is_active' => false]);

    Queue::assertPushed(RefreshCalendarSummaryMessage::class, 2);
    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-05');
    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-06');
});

it('covers the admin bulk approval path after its parent and day changes commit together', function (): void {
    $employee = calendarRefreshEmployee();
    $dayId = calendarRefreshRequestDay($employee, 'day_off', '2026-11-06', 'pending');
    $requestId = DB::table('day_off_request_days')->where('id', $dayId)->value('day_off_request_id');
    calendarRefreshSentOperation('2026-11-06', str_repeat('a', 64), 741);
    Queue::fake();

    DB::transaction(function () use ($requestId): void {
        $request = DayOffRequest::query()->findOrFail($requestId);
        $request->update(['status' => 'approved']);
        $request->days()->update(['status' => 'approved']);
    });

    Queue::assertPushed(RefreshCalendarSummaryMessage::class, fn (RefreshCalendarSummaryMessage $job): bool => $job->date === '2026-11-06');
});

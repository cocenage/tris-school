<?php

use App\Console\Commands\SendTomorrowCalendarEventsNotification;
use App\Models\User;
use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\CalendarSummaryService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/Rome'));
    Http::fake();
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });
    foreach (['day_off_requests', 'vacation_requests'] as $name) {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }
    foreach (['day_off_request_days', 'vacation_request_days'] as $name) {
        Schema::create($name, function (Blueprint $table) use ($name) {
            $table->id();
            $table->unsignedBigInteger($name === 'day_off_request_days' ? 'day_off_request_id' : 'vacation_request_id');
            $table->unsignedBigInteger('user_id');
            $table->date('date');
            $table->string('status');
            $table->timestamps();
        });
    }
});

afterEach(function () {
    foreach (['vacation_request_days', 'day_off_request_days', 'vacation_requests', 'day_off_requests', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function tomorrowStaff(string $name, string $role = 'cleaner', bool $active = true, string $status = 'approved', array $weekends = []): User
{
    return User::create(['name' => $name, 'role' => $role, 'is_active' => $active, 'status' => $status, 'weekend_days' => $weekends]);
}

function tomorrowAbsence(User $user, string $kind, string $dayStatus, string $requestStatus = 'approved', string $date = '2026-10-09'): void
{
    $requestId = DB::table($kind.'_requests')->insertGetId(['user_id' => $user->id, 'status' => $requestStatus, 'reason' => 'личное']);
    DB::table($kind.'_request_days')->insert([
        $kind.'_request_id' => $requestId, 'user_id' => $user->id, 'date' => $date, 'status' => $dayStatus,
    ]);
}

it('restores the original active-cleaner count and approved dated absences', function () {
    $elena = tomorrowStaff('Елена Novara');
    $ludmila = tomorrowStaff('Людмила 1');
    tomorrowStaff('Ольга');
    tomorrowStaff('Неактивная', active: false);
    tomorrowStaff('Ожидает одобрения', status: 'pending');
    tomorrowStaff('Супервайзер', role: 'supervisor');
    tomorrowAbsence($elena, 'day_off', 'approved');
    tomorrowAbsence($ludmila, 'vacation', 'approved', 'partially_approved');

    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--date' => '2026-10-09']))->toBe(0);
    $text = Artisan::output();
    expect($text)->toContain('📅 <b>Сводка на завтра</b>', 'Пятница, 9 октября 2026')
        ->toContain('Работают: <b>1 из 3</b>', 'Не работают: <b>2</b>', 'Статус: <b>🔴 Критическая смена</b>')
        ->toContain('🧹 <b>Клинеры</b> — <b>2</b>', '• Елена Novara — выходной', '• Людмила 1 — отпуск')
        ->not->toContain('Погода', 'Транспорт', 'Доступ', 'Операционка', 'Неактивная', 'Супервайзер', 'личное');
    Http::assertNothingSent();
});

it('uses tomorrow in Rome by default and an explicit date exactly', function () {
    tomorrowStaff('Анна');
    Carbon::setTestNow(Carbon::parse('2026-10-08 23:30:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 23:30:00', 'Europe/Rome'));
    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--json' => true]))->toBe(0);
    $default = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($default['date'])->toBe('2026-10-09')
        ->and($default['shift']['total'])->toBe(1)
        ->and($default['shift']['working'])->toBe(1)
        ->and($default)->not->toHaveKey('weather');
    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--date' => '2026-10-10', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['date'])->toBe('2026-10-10')
        ->and(Artisan::call('telegram:tomorrow-summary-preview', ['--date' => '2026-02-30']))->toBe(1);
});

it('ignores pending and rejected days but keeps approved days of partial requests', function () {
    $approved = tomorrowStaff('Одобренный день');
    $pending = tomorrowStaff('Ожидающий день');
    $rejected = tomorrowStaff('Отклонённый день');
    tomorrowAbsence($approved, 'day_off', 'approved', 'partially_approved');
    tomorrowAbsence($pending, 'day_off', 'pending');
    tomorrowAbsence($rejected, 'vacation', 'rejected');
    tomorrowAbsence($approved, 'vacation', 'approved', 'partially_approved', '2026-10-10');
    $service = app(CalendarSummaryService::class);
    $friday = $service->build('2026-10-09');
    $saturday = $service->build('2026-10-10');
    expect($friday['shift'])->toMatchArray(['total' => 3, 'working' => 2, 'not_working' => 1])
        ->and($friday['workers']['not_working']->pluck('name')->all())->toBe(['Одобренный день'])
        ->and($saturday['workers']['not_working']->first()->not_working_reason)->toStartWith('Отпуск');
});

it('preserves regular weekends and the existing shift-status thresholds', function () {
    $staff = [];
    foreach (range(1, 5) as $number) {
        $staff[] = tomorrowStaff('Клинер '.$number);
    }
    $staff[0]->update(['weekend_days' => [5]]);
    tomorrowAbsence($staff[1], 'day_off', 'approved');
    tomorrowAbsence($staff[2], 'vacation', 'approved');
    $friday = app(CalendarSummaryService::class)->build('2026-10-09');
    $saturday = app(CalendarSummaryService::class)->build('2026-10-10');
    expect($friday['shift'])->toMatchArray([
        'total' => 5, 'working' => 2, 'not_working' => 3,
        'working_percent' => 40, 'level' => 'critical', 'label' => 'Критическая смена',
    ])->and($friday['workers']['not_working']->first()->not_working_reason)->toBe('Регулярный выходной')
        ->and($saturday['shift'])->toMatchArray([
            'total' => 5, 'working' => 5, 'working_percent' => 100, 'level' => 'good', 'label' => 'Нормальная смена',
        ]);
    DB::table('vacation_request_days')->where('user_id', $staff[2]->id)->update(['status' => 'pending']);
    expect(app(CalendarSummaryService::class)->build('2026-10-09')['shift'])->toMatchArray([
        'working' => 3, 'working_percent' => 60, 'level' => 'warning', 'label' => 'Средняя нагрузка',
    ]);
    DB::table('day_off_request_days')->where('user_id', $staff[1]->id)->update(['status' => 'pending']);
    expect(app(CalendarSummaryService::class)->build('2026-10-09')['shift'])->toMatchArray([
        'working' => 4, 'working_percent' => 80, 'level' => 'good', 'label' => 'Нормальная смена',
    ]);
});

it('retains existing supervisor and other role grouping in the renderer', function () {
    $summary = [
        'shift' => ['total' => 3, 'working' => 0, 'not_working' => 3, 'level' => 'critical', 'label' => 'Критическая смена'],
        'workers' => ['not_working' => collect([
            ['name' => 'Супервайзер', 'role' => 'supervisor', 'not_working_reason' => 'Выходной'],
            ['name' => 'Клинер', 'role' => 'cleaner', 'not_working_reason' => 'Отпуск'],
            ['name' => 'Другой', 'role' => 'other', 'not_working_reason' => 'Больничный'],
        ])],
    ];
    $text = app(SendTomorrowCalendarEventsNotification::class)->renderStaffSummary(Carbon::parse('2026-10-09', 'Europe/Rome'), $summary);
    expect($text)->toContain('👔 <b>Супервайзеры</b> — <b>1</b>', '🧹 <b>Клинеры</b> — <b>1</b>', '👤 <b>Остальные</b> — <b>1</b>')
        ->toContain('• Супервайзер — выходной', '• Клинер — отпуск', '• Другой — больничный');
});

it('keeps the scheduled command on its original staff calculation and sends nothing in dry-run', function () {
    tomorrowStaff('Клинер');
    $events = Mockery::mock(CalendarEventsService::class);
    $events->shouldReceive('getEventsForDay')->once()->andReturn(collect());
    app()->instance(CalendarEventsService::class, $events);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendMessage');
    app()->instance(TelegramBotService::class, $bot);
    expect(Artisan::call('calendar:notify-tomorrow', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Работают: <b>1 из 1</b>', 'Статус: <b>🟢 Нормальная смена</b>')
        ->not->toContain('Погода', 'Транспорт', 'Доступ', 'Операционка');
    Http::assertNothingSent();
});

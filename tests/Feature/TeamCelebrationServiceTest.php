<?php

use App\Services\Calendar\TeamCelebrationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'services.telegram.chat_id_celebrations' => 'celebrations-test-chat',
        'services.telegram.thread_id_celebrations' => '7',
    ]);

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->string('telegram_username')->nullable();
        $table->date('birthday')->nullable();
        $table->date('work_started_at')->nullable();
        $table->boolean('dip')->default(false);
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });

    Schema::create('calendar_events', function (Blueprint $table) {
        $table->id();
        $table->string('type');
        $table->string('title');
        $table->text('description')->nullable();
        $table->date('start_date');
        $table->date('end_date')->nullable();
        $table->string('repeat_type')->default('none');
        $table->date('repeat_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('priority')->default(0);
        $table->timestamps();
    });

    Schema::create('day_off_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('day_off_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('day_off_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status');
        $table->timestamps();
    });
    Schema::create('vacation_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('vacation_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('vacation_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status');
        $table->timestamps();
    });
});

afterEach(function () {
    foreach ([
        'vacation_request_days',
        'vacation_requests',
        'day_off_request_days',
        'day_off_requests',
        'calendar_events',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

function insertCelebrationUser(array $attributes): int
{
    return DB::table('users')->insertGetId(array_merge([
        'name' => 'Командный сотрудник',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'telegram_id' => null,
        'telegram_username' => null,
        'birthday' => null,
        'work_started_at' => null,
        'dip' => false,
        'weekend_days' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

it('greets birthdays of active approved non-admin users without exposing birth date or age', function () {
    $withId = insertCelebrationUser([
        'name' => 'Анна', 'telegram_id' => '123456789', 'birthday' => '1992-10-09',
    ]);
    $withUsername = insertCelebrationUser([
        'name' => 'Мария', 'telegram_username' => 'maria_team', 'birthday' => '1990-10-09',
    ]);
    insertCelebrationUser(['name' => 'Администратор', 'role' => 'admin', 'birthday' => '1980-10-09']);
    insertCelebrationUser(['name' => 'Неодобренный', 'status' => 'pending', 'birthday' => '1985-10-09']);
    insertCelebrationUser(['name' => 'Неактивный', 'is_active' => false, 'birthday' => '1988-10-09']);

    $events = app(TeamCelebrationService::class)->eventsForDate(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $byId = $events->keyBy('source_id');

    expect($events)->toHaveCount(2)
        ->and($byId->get((string) $withId))
        ->toMatchArray(['kind' => 'birthday', 'source_type' => 'User'])
        ->and($byId->get((string) $withId)['text'])->toContain('tg://user?id=123456789')
        ->and($byId->get((string) $withUsername)['text'])->toContain('https://t.me/maria_team')
        ->and($events->pluck('text')->implode(' '))->not->toContain('1992', '1990', '34 лет', '36 лет')
        ->and($events->pluck('operation_key')->unique())->toHaveCount(2)
        ->and($events->pluck('photo_path')->filter())->toBeEmpty();
});

it('announces only full work years and maps February twenty ninth to February twenty eighth', function () {
    $leapUserId = insertCelebrationUser([
        'name' => 'Иван', 'work_started_at' => '2020-02-29',
    ]);
    insertCelebrationUser(['name' => 'Сегодня первый день', 'work_started_at' => '2026-02-28']);

    $service = app(TeamCelebrationService::class);
    $nonLeap = $service->eventsForDate(CarbonImmutable::parse('2021-02-28', 'Europe/Rome'));
    $leap = $service->eventsForDate(CarbonImmutable::parse('2024-02-29', 'Europe/Rome'));
    $tooEarly = $service->eventsForDate(CarbonImmutable::parse('2020-02-28', 'Europe/Rome'));

    expect($nonLeap)->toHaveCount(1)
        ->and($nonLeap->first())
        ->toMatchArray(['kind' => 'work_anniversary', 'source_id' => (string) $leapUserId])
        ->and($nonLeap->first()['text'])->toContain('1 год')
        ->and($leap)->toHaveCount(1)
        ->and($leap->first()['text'])->toContain('4 года')
        ->and($tooEarly)->toBeEmpty();
});

it('sends a multi-day active calendar holiday only on its first date', function () {
    DB::table('calendar_events')->insert([
        'type' => 'holiday',
        'title' => 'Праздник команды',
        'description' => null,
        'start_date' => '2026-10-09',
        'end_date' => '2026-10-11',
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => true,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('calendar_events')->insert([
        'type' => 'holiday',
        'title' => 'Выключенный праздник',
        'description' => null,
        'start_date' => '2026-10-09',
        'end_date' => null,
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => false,
        'priority' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(TeamCelebrationService::class);
    $firstDay = $service->eventsForDate(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $middleDay = $service->eventsForDate(CarbonImmutable::parse('2026-10-10', 'Europe/Rome'));

    expect($firstDay)->toHaveCount(1)
        ->and($firstDay->first())
        ->toMatchArray(['kind' => 'calendar_holiday', 'source_type' => 'CalendarEvent', 'text' => '🎉 <b>Праздник команды</b>'])
        ->and($middleDay)->toBeEmpty();
});

it('includes the configured route in the stable event key', function () {
    insertCelebrationUser(['birthday' => '1992-10-09']);
    $date = CarbonImmutable::parse('2026-10-09', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);
    $firstKey = $service->eventsForDate($date)->first()['operation_key'];

    config(['services.telegram.thread_id_celebrations' => '8']);
    $secondKey = $service->eventsForDate($date)->first()['operation_key'];

    expect($secondKey)->not->toBe($firstKey);
});

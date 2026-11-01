<?php

use App\Services\Calendar\CalendarEventsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
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

it('labels calendar holidays, birthdays and work anniversaries with explicit subtypes without changing their event type', function () {
    $userId = DB::table('users')->insertGetId([
        'name' => 'Подтверждённый сотрудник',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'birthday' => '1992-10-09',
        'work_started_at' => '2020-10-09',
        'dip' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $holidayId = DB::table('calendar_events')->insertGetId([
        'type' => 'holiday',
        'title' => 'Командный праздник',
        'description' => 'Описание праздника',
        'start_date' => '2026-10-09',
        'end_date' => null,
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => true,
        'priority' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('calendar_events')->insert([
        'type' => 'holiday',
        'title' => 'Неактивный праздник',
        'description' => null,
        'start_date' => '2026-10-09',
        'end_date' => null,
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => false,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $events = app(CalendarEventsService::class)->getEventsForDay(Carbon::parse('2026-10-09', 'Europe/Rome'));
    $holiday = $events->firstWhere('id', $holidayId.'_20261009');
    $birthday = $events->firstWhere('id', 'birthday_'.$userId.'_2026');
    $anniversary = $events->firstWhere('id', 'work_'.$userId.'_2026');

    expect($holiday)->toMatchArray([
        'id' => $holidayId.'_20261009',
        'type' => 'holiday',
        'title' => 'Командный праздник',
        'description' => 'Описание праздника',
        'subtype' => 'calendar_holiday',
    ])->and($birthday)
        ->toMatchArray(['type' => 'holiday', 'subtype' => 'birthday'])
        ->and($anniversary)
        ->toMatchArray(['type' => 'holiday', 'subtype' => 'work_anniversary'])
        ->and($events->pluck('title')->implode(' '))->not->toContain('Неактивный праздник')
        ->and(array_keys($holiday))->toContain('id', 'title', 'description', 'short', 'type', 'priority', 'start', 'end', 'style');
});

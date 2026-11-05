<?php

use App\Console\Commands\SendTomorrowCalendarEventsNotification;
use App\Models\User;
use App\Services\Calendar\CalendarSummaryService;
use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'Europe/Rome'));

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->date('work_started_at')->nullable();
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });

    DB::table('users')->insert([
        'id' => 21,
        'name' => 'Сотрудник с известным графиком на дату запуска',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'work_started_at' => '2020-04-15',
        'weekend_days' => json_encode([7]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        '2026_11_04_160000_create_employee_work_schedule_versions_table.php',
        '2026_11_04_160001_create_employee_calendar_adjustments_table.php',
        '2026_11_04_160002_create_user_calendar_permissions_table.php',
    ] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }

    Schema::create('day_off_requests', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });

    Schema::create('day_off_request_days', function (Blueprint $table) {
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

    Schema::create('vacation_requests', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->unsignedInteger('days_count')->nullable();
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });

    Schema::create('vacation_request_days', function (Blueprint $table) {
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
});

afterEach(function () {
    foreach ([
        'vacation_request_days',
        'vacation_requests',
        'day_off_request_days',
        'day_off_requests',
        'user_calendar_permissions',
        'employee_calendar_adjustments',
        'employee_work_schedule_versions',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Carbon::setTestNow();
});

it('does not infer a historical recurring schedule from the current user profile', function () {
    $summary = app(CalendarSummaryService::class)->build('2026-11-03');

    expect($summary['shift']['level'])->toBe('unknown')
        ->and($summary['workers']['unknown_count'])->toBe(1)
        ->and($summary['workers']['working_count'])->toBe(0);
});

it('keeps pre-employment dates neutral even if an old request day is approved', function () {
    $employee = User::findOrFail(21);
    $requestId = DB::table('day_off_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'approved',
        'reason' => 'fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        'day_off_request_id' => $requestId,
        'user_id' => $employee->id,
        'date' => '2019-12-31',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $day = app(EmployeeWorkCalendarService::class)->forEmployee(
        $employee,
        Carbon::parse('2019-12-31'),
        Carbon::parse('2019-12-31'),
    )->get('2019-12-31');

    expect($day['state'])->toBe('not_employed')
        ->and($day['schedule_known'])->toBeTrue()
        ->and(collect($day['sources'])->first()['status'])->toBe('approved');
});

it('does not let rejected request days change the effective schedule', function () {
    $requestId = DB::table('day_off_requests')->insertGetId([
        'user_id' => 21,
        'status' => 'rejected',
        'reason' => 'fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        'day_off_request_id' => $requestId,
        'user_id' => 21,
        'date' => '2026-11-06',
        'status' => 'rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $day = app(EmployeeWorkCalendarService::class)->forEmployee(
        User::findOrFail(21),
        Carbon::parse('2026-11-06'),
        Carbon::parse('2026-11-06'),
    )->get('2026-11-06');

    expect($day['state'])->toBe('planned_work')
        ->and($day['pending'])->toBeFalse()
        ->and(collect($day['sources'])->first()['status'])->toBe('rejected');
});

it('preserves the category of a manually recorded absence in staffing and tomorrow summaries', function () {
    DB::table('employee_calendar_adjustments')->insert([
        'user_id' => 21,
        'starts_on' => '2026-11-04',
        'operation' => 'set_state',
        'category' => 'unpaid_leave',
        'previous_state' => json_encode(['state' => 'planned_work']),
        'new_state' => json_encode(['state' => 'approved_absence']),
        'actor_id' => null,
        'actor_name_snapshot' => 'fixture manager',
        'reason' => 'fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $summary = app(CalendarSummaryService::class)->build('2026-11-04');
    $digest = app(SendTomorrowCalendarEventsNotification::class)
        ->renderStaffSummary(Carbon::parse('2026-11-04', 'Europe/Rome'), $summary);

    expect($summary['workers']['not_working'][0]->not_working_reason)->toBe('Неоплачиваемый отпуск')
        ->and($digest)->toContain('неоплачиваемый отпуск');
});

it('applies schedule versions only from their effective date', function () {
    $employee = User::findOrFail(21);
    $service = app(EmployeeWorkCalendarService::class);

    $employee->workScheduleVersions()->create([
        'effective_from' => '2026-11-06',
        'version_type' => 'schedule_change',
        'employment_start_date' => '2020-04-15',
        'weekend_days' => [5],
        'previous_values' => ['weekend_days' => [7]],
        'new_values' => ['employment_start_date' => '2020-04-15', 'weekend_days' => [5]],
        'reason' => 'Согласованная проверка effective date',
    ]);

    $projection = $service->forEmployee($employee, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06'));

    expect($projection->get('2026-11-05')['state'])->toBe('planned_work')
        ->and($projection->get('2026-11-06')['state'])->toBe('regular_day_off');
});

it('counts approved absence once, keeps pending separate, and marks missing schedule as unknown', function () {
    $employee = User::findOrFail(21);
    $pendingRequestId = DB::table('day_off_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'partially_approved',
        'reason' => 'private reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        'day_off_request_id' => $pendingRequestId,
        'user_id' => $employee->id,
        'date' => '2026-11-05',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $approvedVacationId = DB::table('vacation_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'partially_approved',
        'reason' => 'private vacation reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vacation_request_days')->insert([
        'vacation_request_id' => $approvedVacationId,
        'user_id' => $employee->id,
        'date' => '2026-11-05',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $unknownUserId = DB::table('users')->insertGetId([
        'name' => 'График неизвестен',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'work_started_at' => '2020-01-01',
        'weekend_days' => json_encode([7]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $summary = app(CalendarSummaryService::class)->build('2026-11-05');
    $projection = app(EmployeeWorkCalendarService::class)
        ->forEmployee($employee, Carbon::parse('2026-11-05'), Carbon::parse('2026-11-05'));
    $digest = app(SendTomorrowCalendarEventsNotification::class)
        ->renderStaffSummary(Carbon::parse('2026-11-05', 'Europe/Rome'), $summary);

    expect($projection->get('2026-11-05')['state'])->toBe('approved_absence')
        ->and($projection->get('2026-11-05')['pending'])->toBeTrue()
        ->and(count($projection->get('2026-11-05')['sources']))->toBe(2)
        ->and($summary['shift']['level'])->toBe('unknown')
        ->and($summary['workers']['total'])->toBe(2)
        ->and($summary['workers']['not_working_count'])->toBe(1)
        ->and($summary['workers']['unknown_count'])->toBe(1)
        ->and($summary['workers']['by_role']['cleaner']['absent_count'])->toBe(1)
        ->and($summary['workers']['by_role']['cleaner']['unknown_count'])->toBe(1)
        ->and($digest)->toContain('график клинеров подтверждён не полностью', 'График не подтверждён у <b>1</b> сотрудника')
        ->not->toContain('Все клинеры доступны')
        ->and(User::findOrFail($unknownUserId)->workScheduleVersions()->count())->toBe(0);
});

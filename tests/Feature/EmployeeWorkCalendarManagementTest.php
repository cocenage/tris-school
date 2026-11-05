<?php

use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarManagementService;
use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', 'Europe/Rome'));

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
        [
            'id' => 21,
            'name' => 'Клинер',
            'role' => 'cleaner',
            'status' => 'approved',
            'is_active' => true,
            'work_started_at' => '2020-04-15',
            'weekend_days' => json_encode([7]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 99,
            'name' => 'Администратор',
            'role' => 'admin',
            'status' => 'approved',
            'is_active' => true,
            'work_started_at' => '2020-01-01',
            'weekend_days' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 100,
            'name' => 'Супервайзер без разрешений',
            'role' => 'supervisor',
            'status' => 'approved',
            'is_active' => true,
            'work_started_at' => '2020-01-01',
            'weekend_days' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
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
    Schema::disableForeignKeyConstraints();
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
    Schema::enableForeignKeyConstraints();

    Carbon::setTestNow();
});

it('appends a future schedule version without changing earlier projections', function () {
    $employee = User::findOrFail(21);
    $admin = User::findOrFail(99);
    $service = app(EmployeeWorkCalendarManagementService::class);

    $version = $service->changeSchedule(
        $employee,
        ['weekend_days' => [5]],
        Carbon::parse('2026-11-06', 'Europe/Rome'),
        $admin,
        'Изменение графика по согласованию',
    );
    $projection = app(EmployeeWorkCalendarService::class)->forEmployee(
        $employee->fresh(),
        Carbon::parse('2026-11-05', 'Europe/Rome'),
        Carbon::parse('2026-11-06', 'Europe/Rome'),
    );

    expect($version->effective_from->toDateString())->toBe('2026-11-06')
        ->and($projection->get('2026-11-05')['state'])->toBe('planned_work')
        ->and($projection->get('2026-11-06')['state'])->toBe('regular_day_off')
        ->and($employee->fresh()->weekend_days)->toBe([5]);
});

it('rejects a retroactive recurring schedule change and denies supervisors by default', function () {
    $service = app(EmployeeWorkCalendarManagementService::class);
    $employee = User::findOrFail(21);

    expect(fn () => $service->changeSchedule(
        $employee,
        ['weekend_days' => [5]],
        Carbon::parse('2026-11-04', 'Europe/Rome'),
        User::findOrFail(99),
        'Причина',
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->changeSchedule(
        $employee,
        ['weekend_days' => [5]],
        Carbon::parse('2026-11-05', 'Europe/Rome'),
        User::findOrFail(100),
        'Причина',
    ))->toThrow(HttpException::class);
});

it('preserves both schedule versions when a stale form is saved after a newer edit', function () {
    $employee = User::findOrFail(21);
    $staleEmployee = User::findOrFail(21);
    $service = app(EmployeeWorkCalendarManagementService::class);
    $effectiveDate = Carbon::parse('2026-11-06', 'Europe/Rome');
    $admin = User::findOrFail(99);

    $service->changeSchedule($employee, ['weekend_days' => [5]], $effectiveDate, $admin, 'Первое изменение');
    $second = $service->changeSchedule($staleEmployee, ['weekend_days' => [4]], $effectiveDate, $admin, 'Последующее уточнение');

    expect($second->previous_values['weekend_days'])->toBe([5])
        ->and($employee->workScheduleVersions()->count())->toBe(3)
        ->and($employee->fresh()->weekend_days)->toBe([4]);
});

it('requires a reason and records profile employment-date changes atomically', function () {
    $service = app(EmployeeWorkCalendarManagementService::class);
    $employee = User::findOrFail(21);
    $admin = User::findOrFail(99);

    expect(fn () => $service->recordProfileStartDateChange(
        $employee,
        Carbon::parse('2020-04-15'),
        Carbon::parse('2020-04-20'),
        $employee,
        '   ',
    ))->toThrow(ValidationException::class);

    $version = $service->recordProfileStartDateChange(
        $employee,
        Carbon::parse('2020-04-15'),
        Carbon::parse('2020-04-20'),
        $employee,
        'Дата уточнена по документам',
    );

    expect($version->version_type)->toBe('employment_start_change')
        ->and($version->actor_id)->toBe($employee->id)
        ->and($version->reason)->toBe('Дата уточнена по документам')
        ->and($employee->fresh()->work_started_at->toDateString())->toBe('2020-04-20')
        ->and($employee->workScheduleVersions()->count())->toBe(2);
});

it('cancels only a linked approved request day without changing its original record', function () {
    $employee = User::findOrFail(21);
    $requestId = DB::table('day_off_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'approved',
        'reason' => 'request reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dayId = DB::table('day_off_request_days')->insertGetId([
        'day_off_request_id' => $requestId,
        'user_id' => $employee->id,
        'date' => '2026-11-05',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $adjustment = app(EmployeeWorkCalendarManagementService::class)->adjust(
        $employee,
        [
            'operation' => 'cancel_request_day',
            'date' => '2026-11-05',
            'request_type' => 'day_off_request_day',
            'request_day_id' => $dayId,
        ],
        User::findOrFail(99),
        'Согласовано с сотрудником',
    );
    $day = DB::table('day_off_request_days')->where('id', $dayId)->first();
    $projection = app(EmployeeWorkCalendarService::class)->forEmployee(
        $employee,
        Carbon::parse('2026-11-05'),
        Carbon::parse('2026-11-05'),
    )->get('2026-11-05');

    expect($day->status)->toBe('approved')
        ->and($projection['state'])->toBe('planned_work')
        ->and(collect($projection['sources'])->contains(fn (array $source) => ($source['id'] ?? null) === $adjustment->id))->toBeTrue()
        ->and($projection['sources'][0]['superseded'])->toBeTrue()
        ->and($adjustment->previous_state['state'])->toBe('approved_absence')
        ->and($adjustment->reason)->toBe('Согласовано с сотрудником');
});

it('moves an approved request date and restores the original history when the correction is reversed', function () {
    $employee = User::findOrFail(21);
    $requestId = DB::table('vacation_requests')->insertGetId([
        'user_id' => $employee->id,
        'status' => 'approved',
        'reason' => 'vacation reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $dayId = DB::table('vacation_request_days')->insertGetId([
        'vacation_request_id' => $requestId,
        'user_id' => $employee->id,
        'date' => '2026-11-05',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(EmployeeWorkCalendarManagementService::class);
    $move = $service->adjust(
        $employee,
        [
            'operation' => 'move_request_day',
            'date' => '2026-11-05',
            'replacement_date' => '2026-11-07',
            'request_type' => 'vacation_request_day',
            'request_day_id' => $dayId,
        ],
        User::findOrFail(99),
        'Перенос отпуска по согласованию',
    );
    $afterMove = app(EmployeeWorkCalendarService::class)->forEmployee(
        $employee,
        Carbon::parse('2026-11-05'),
        Carbon::parse('2026-11-07'),
    );
    $service->adjust(
        $employee,
        ['operation' => 'reverse', 'adjustment_id' => $move->id],
        User::findOrFail(99),
        'Отмена корректировки после уточнения',
    );
    $afterReverse = app(EmployeeWorkCalendarService::class)->forEmployee(
        $employee,
        Carbon::parse('2026-11-05'),
        Carbon::parse('2026-11-07'),
    );

    expect($afterMove->get('2026-11-05')['state'])->toBe('planned_work')
        ->and($afterMove->get('2026-11-07')['state'])->toBe('approved_absence')
        ->and($afterReverse->get('2026-11-05')['state'])->toBe('approved_absence')
        ->and($afterReverse->get('2026-11-07')['state'])->toBe('planned_work')
        ->and(DB::table('vacation_request_days')->where('id', $dayId)->value('status'))->toBe('approved');
});

it('captures each prior state in a ranged correction and rejects unrecognized categories', function () {
    $employee = User::findOrFail(21);
    $admin = User::findOrFail(99);
    $service = app(EmployeeWorkCalendarManagementService::class);

    expect(fn () => $service->adjust(
        $employee,
        ['operation' => 'set_state', 'date' => '2026-11-06', 'state' => 'approved_absence', 'category' => 'unexpected'],
        $admin,
        'Причина',
    ))->toThrow(ValidationException::class);

    $adjustment = $service->adjust(
        $employee,
        ['operation' => 'set_state', 'date' => '2026-11-06', 'ends_on' => '2026-11-07', 'state' => 'regular_day_off'],
        $admin,
        'Корректировка двух дат',
    );

    expect($adjustment->previous_state['states']['2026-11-06']['state'])->toBe('planned_work')
        ->and($adjustment->previous_state['states']['2026-11-07']['state'])->toBe('planned_work');
});

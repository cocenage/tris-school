<?php

use App\Filament\Pages\EmployeeWorkCalendarReport;
use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
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
        ['id' => 21, 'name' => 'Клинер', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true, 'work_started_at' => '2020-01-01', 'weekend_days' => json_encode([7]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => 99, 'name' => 'Супервайзер', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'work_started_at' => '2020-01-01', 'weekend_days' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ['id' => 100, 'name' => 'Администратор', 'role' => 'admin', 'status' => 'approved', 'is_active' => true, 'work_started_at' => '2020-01-01', 'weekend_days' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
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
        'vacation_request_days', 'vacation_requests', 'day_off_request_days', 'day_off_requests',
        'user_calendar_permissions', 'employee_calendar_adjustments', 'employee_work_schedule_versions', 'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();
    Carbon::setTestNow();
});

it('aggregates unique employee-days, overlapping requests, pending dates, and corrections', function () {
    $employee = User::findOrFail(21);
    $dayOffId = DB::table('day_off_requests')->insertGetId([
        'user_id' => $employee->id, 'status' => 'approved', 'reason' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $vacationId = DB::table('vacation_requests')->insertGetId([
        'user_id' => $employee->id, 'status' => 'approved', 'reason' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        ['day_off_request_id' => $dayOffId, 'user_id' => $employee->id, 'date' => '2026-11-06', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ['day_off_request_id' => $dayOffId, 'user_id' => $employee->id, 'date' => '2026-11-07', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('vacation_request_days')->insert([
        ['vacation_request_id' => $vacationId, 'user_id' => $employee->id, 'date' => '2026-11-07', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $pendingId = DB::table('day_off_requests')->insertGetId([
        'user_id' => $employee->id, 'status' => 'pending', 'reason' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        'day_off_request_id' => $pendingId, 'user_id' => $employee->id, 'date' => '2026-11-10', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('employee_calendar_adjustments')->insert([
        'user_id' => $employee->id,
        'starts_on' => '2026-11-09',
        'operation' => 'set_state',
        'category' => 'unpaid_leave',
        'previous_state' => json_encode(['state' => 'planned_work']),
        'new_state' => json_encode(['state' => 'approved_absence']),
        'actor_id' => 100,
        'actor_name_snapshot' => 'Администратор',
        'reason' => 'fixture correction',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(EmployeeWorkCalendarStatisticsService::class)->forPeriod(
        Carbon::parse('2026-11-05'),
        Carbon::parse('2026-11-11'),
    );
    $row = collect($report['employees'])->firstWhere('employee_id', 21);

    expect($row['planned_workdays'])->toBe(3)
        ->and($row['regular_off_days'])->toBe(1)
        ->and($row['unknown_schedule_days'])->toBe(0)
        ->and($row['approved_absence_days'])->toBe(3)
        ->and($row['approved_day_off_days'])->toBe(1)
        ->and($row['approved_vacation_days'])->toBe(1)
        ->and($row['approved_unpaid_leave_days'])->toBe(1)
        ->and($row['pending_request_days'])->toBe(1)
        ->and($row['manual_change_days'])->toBe(1)
        ->and($report['totals']['approved_absence_days'])->toBe(3);
});

it('filters by an active employee and rejects an invalid employee or an overlong range', function () {
    $stats = app(EmployeeWorkCalendarStatisticsService::class);

    expect($stats->forPeriod(Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06'), 21)['employee_count'])->toBe(1)
        ->and(fn () => $stats->forPeriod(Carbon::parse('2026-11-05'), Carbon::parse('2026-11-06'), 100))->toThrow(HttpException::class)
        ->and(fn () => $stats->forPeriod(Carbon::parse('2026-01-01'), Carbon::parse('2027-01-02')))->toThrow(ValidationException::class);

    $annual = $stats->forPeriod(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    expect($annual['from'])->toBe('2026-01-01')
        ->and($annual['through'])->toBe('2026-12-31')
        ->and($annual['employee_count'])->toBe(2)
        ->and($annual['totals']['unknown_schedule_days'])->toBeGreaterThan(0);
});

it('keeps report and export access default-deny for supervisors and admin-only bypass', function () {
    $supervisor = User::findOrFail(99);
    $admin = User::findOrFail(100);

    auth()->setUser($supervisor);
    expect(EmployeeWorkCalendarReport::canAccess())->toBeFalse()
        ->and($supervisor->canExportEmployeeCalendars())->toBeFalse()
        ->and(fn () => app(EmployeeWorkCalendarReport::class)->exportCsv())->toThrow(HttpException::class);

    $supervisor->calendarPermission()->create([
        'can_view_all_calendars' => true,
        'can_edit' => false,
        'can_export' => false,
    ]);
    auth()->setUser($supervisor->fresh());
    expect(EmployeeWorkCalendarReport::canAccess())->toBeTrue()
        ->and(auth()->user()->canExportEmployeeCalendars())->toBeFalse()
        ->and(fn () => app(EmployeeWorkCalendarReport::class)->exportCsv())->toThrow(HttpException::class);

    auth()->user()->calendarPermission()->update(['can_export' => true]);
    expect(auth()->user()->fresh()->canExportEmployeeCalendars())->toBeTrue();

    auth()->setUser($admin);
    expect(EmployeeWorkCalendarReport::canAccess())->toBeTrue()
        ->and($admin->canExportEmployeeCalendars())->toBeTrue();
});

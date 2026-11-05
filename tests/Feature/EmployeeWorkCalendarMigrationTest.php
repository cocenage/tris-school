<?php

use App\Models\EmployeeWorkScheduleVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'Europe/Rome'));

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->date('work_started_at')->nullable();
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });

    DB::table('users')->insert([
        [
            'id' => 10,
            'name' => 'Клинер с заполненным графиком',
            'role' => 'cleaner',
            'status' => 'approved',
            'is_active' => true,
            'work_started_at' => '2021-03-15',
            'weekend_days' => json_encode([6, 7]),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 11,
            'name' => 'Клинер без подтверждённого графика',
            'role' => 'cleaner',
            'status' => 'approved',
            'is_active' => true,
            'work_started_at' => null,
            'weekend_days' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

afterEach(function () {
    Schema::dropIfExists('user_calendar_permissions');
    Schema::dropIfExists('employee_calendar_adjustments');
    Schema::dropIfExists('employee_work_schedule_versions');
    Schema::dropIfExists('users');
    Carbon::setTestNow();
});

it('creates an additive calendar schema and snapshots only known current schedules at activation', function () {
    foreach ([
        '2026_11_04_160000_create_employee_work_schedule_versions_table.php',
        '2026_11_04_160001_create_employee_calendar_adjustments_table.php',
        '2026_11_04_160002_create_user_calendar_permissions_table.php',
    ] as $migration) {
        $path = database_path('migrations/'.$migration);

        if (is_file($path)) {
            (require $path)->up();
        }
    }

    expect(Schema::hasTable('employee_work_schedule_versions'))->toBeTrue()
        ->and(Schema::hasTable('employee_calendar_adjustments'))->toBeTrue()
        ->and(Schema::hasTable('user_calendar_permissions'))->toBeTrue()
        ->and(Schema::hasColumns('employee_work_schedule_versions', [
            'user_id',
            'effective_from',
            'employment_start_date',
            'weekend_days',
            'previous_values',
            'new_values',
            'actor_id',
            'reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('employee_calendar_adjustments', [
            'user_id',
            'starts_on',
            'ends_on',
            'operation',
            'category',
            'supersedes_request_type',
            'supersedes_request_day_id',
            'replacement_date',
            'actor_id',
            'reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('user_calendar_permissions', [
            'user_id',
            'can_view_all_calendars',
            'can_edit',
            'can_export',
        ]))->toBeTrue();

    $version = EmployeeWorkScheduleVersion::query()->where('user_id', 10)->first();

    expect($version)->not->toBeNull()
        ->and($version->effective_from->toDateString())->toBe('2026-11-04')
        ->and($version->weekend_days)->toBe([6, 7])
        ->and($version->version_type)->toBe('activation_snapshot')
        ->and($version->reason)->not->toBeEmpty()
        ->and(DB::table('employee_work_schedule_versions')->where('user_id', 11)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', 10)->value('work_started_at'))->toBe('2021-03-15')
        ->and(DB::table('users')->where('id', 10)->value('weekend_days'))->toBe(json_encode([6, 7]))
        ->and(DB::table('users')->count())->toBe(2);

    expect(fn () => $version->delete())->toThrow(RuntimeException::class)
        ->and(fn () => (require database_path('migrations/2026_11_04_160000_create_employee_work_schedule_versions_table.php'))->down())
        ->toThrow(RuntimeException::class);

    $supervisor = User::query()->findOrFail(10);
    $supervisor->forceFill(['role' => 'supervisor'])->save();
    $supervisor->calendarPermission()->create([]);

    expect($supervisor->fresh()->canViewAllEmployeeCalendars())->toBeFalse()
        ->and($supervisor->fresh()->canEditEmployeeCalendars())->toBeFalse()
        ->and($supervisor->fresh()->canExportEmployeeCalendars())->toBeFalse();

    $admin = User::query()->findOrFail(11);
    $admin->forceFill(['role' => 'admin'])->save();

    expect($admin->canViewAllEmployeeCalendars())->toBeTrue()
        ->and($admin->canEditEmployeeCalendars())->toBeTrue()
        ->and($admin->canExportEmployeeCalendars())->toBeTrue();
});

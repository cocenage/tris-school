<?php

use App\Models\User;
use App\Services\UserApplicationBadgeService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $badge = Mockery::mock(UserApplicationBadgeService::class);
    $badge->shouldReceive('label')->zeroOrMoreTimes()->andReturn(null);
    app()->instance(UserApplicationBadgeService::class, $badge);
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'Europe/Rome'));

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->date('birthday')->nullable();
        $table->date('work_started_at')->nullable();
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });

    DB::table('users')->insert([
        'id' => 21,
        'name' => 'Клинер календаря',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'birthday' => '1990-02-03',
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

    Schema::create('user_panel_accesses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->string('panel');
        $table->boolean('can_access')->default(false);
        $table->timestamps();
    });

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
    Schema::create('calendar_events', function (Blueprint $table) {
        $table->id();
        $table->boolean('is_active')->default(false);
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    foreach ([
        'vacation_request_days',
        'vacation_requests',
        'calendar_events',
        'user_panel_accesses',
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

it('shows only the authenticated employee calendar and keeps it read-only', function () {
    $employee = User::findOrFail(21);
    $this->actingAs($employee)
        ->get(route('page-profile.calendar', ['employee' => 999]))
        ->assertOk()
        ->assertSee('Мой календарь');

    $component = Livewire::actingAs($employee)
        ->test('profile.page-calendar')
        ->assertSee('График не зафиксирован')
        ->call('selectDate', '2026-11-05')
        ->assertSee('5 ноября 2026')
        ->assertSee('Рабочий день по графику');

    expect(method_exists($component->instance(), 'save'))->toBeFalse()
        ->and(method_exists($component->instance(), 'updateSchedule'))->toBeFalse()
        ->and(property_exists($component->instance(), 'employeeId'))->toBeFalse();
});

it('denies users outside the employee roles and rejects dates outside the visible month', function () {
    $employee = User::findOrFail(21);
    $component = Livewire::actingAs($employee)->test('profile.page-calendar');

    $component->call('selectDate', '2026-12-15')->assertForbidden();

    $adminId = DB::table('users')->insertGetId([
        'name' => 'Администратор',
        'role' => 'admin',
        'status' => 'approved',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(User::findOrFail($adminId))
        ->get(route('page-profile.calendar'))
        ->assertForbidden();
});

it('requires an approved authenticated employee', function () {
    $pendingId = DB::table('users')->insertGetId([
        'name' => 'Ожидающий доступ',
        'role' => 'cleaner',
        'status' => 'pending',
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(User::findOrFail($pendingId))
        ->get(route('page-profile.calendar'))
        ->assertRedirect(route('access.pending'));
});

it('requires a reason before saving a changed employment date and appends its audit version', function () {
    $employee = User::findOrFail(21);
    $component = Livewire::actingAs($employee)->test('page-profile')
        ->set('profileWorkStartedAt', '2020-04-20')
        ->set('profileWorkStartedReason', '')
        ->call('saveProfile')
        ->assertHasErrors(['profileWorkStartedReason' => 'required']);

    expect($employee->fresh()->work_started_at->toDateString())->toBe('2020-04-15')
        ->and($employee->workScheduleVersions()->count())->toBe(1);

    $component->set('profileWorkStartedReason', 'Дата уточнена по документам')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $version = $employee->workScheduleVersions()->latest('id')->firstOrFail();
    expect($employee->fresh()->work_started_at->toDateString())->toBe('2020-04-20')
        ->and($version->version_type)->toBe('employment_start_change')
        ->and($version->actor_id)->toBe($employee->id)
        ->and($version->reason)->toBe('Дата уточнена по документам');
});

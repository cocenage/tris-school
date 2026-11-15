<?php

use App\Models\User;
use App\Services\RolePreviewContext;
use App\Services\UserApplicationBadgeService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    Schema::create('user_calendar_type_accesses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id');
        $table->string('type');
        $table->boolean('can_view');
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
        'user_calendar_type_accesses',
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
        ->and(method_exists($component->instance(), 'updateSchedule'))->toBeFalse();
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

it('opens an active employee calendar from the client profile and lets the admin switch months', function () {
    $admin = createCalendarPageUser('admin', 'Администратор календаря');
    $supervisor = createCalendarPageUser('supervisor', 'Доступный супервайзер');
    $inactive = createCalendarPageUser('cleaner', 'Уволенный клинер', false);
    $pending = createCalendarPageUser('cleaner', 'Ожидающий клинер', true, 'pending');
    $this->actingAs($admin)
        ->get(route('page-profile'))
        ->assertOk()
        ->assertSee('Календари сотрудников')
        ->assertSee(route('page-profile.employee-calendars'));

    $this->get(route('page-profile.employee-calendars'))
        ->assertOk()
        ->assertSee('Клинер календаря')
        ->assertSee($supervisor->name)
        ->assertSee(route('employees.calendar', ['employee' => 21]))
        ->assertDontSee($inactive->name)
        ->assertDontSee($pending->name)
        ->assertDontSee(route('employees.calendar', $admin));

    $this->get(route('employees.calendar', ['employee' => 21]))
        ->assertOk()
        ->assertSee('Клинер календаря')
        ->assertSee(route('page-profile.employee-calendars'));

    Livewire::test('profile.page-calendar', ['employee' => 21])
        ->call('nextMonth')
        ->assertSet('month', '2026-12')
        ->call('selectDate', '2026-12-05')
        ->assertSee('5 декабря 2026');

    foreach ([$inactive, $pending, $admin] as $employee) {
        $this->get(route('employees.calendar', $employee))->assertNotFound();
    }
});

it('checks the calendar grant at page entry and again on Livewire requests', function () {
    $cleaner = User::findOrFail(21);
    $supervisor = createCalendarPageUser('supervisor', 'Проверяющий супервайзер');

    foreach ([$cleaner, $supervisor] as $viewer) {
        $this->actingAs($viewer)->get(route('page-profile.employee-calendars'))->assertForbidden();
        $this->get(route('employees.calendar', $cleaner))->assertForbidden();
        $this->get(route('page-profile.calendar'))->assertOk();
    }

    $permission = $supervisor->calendarPermission()->create(['can_view_all_calendars' => true]);
    $this->actingAs($supervisor)->get(route('page-profile.employee-calendars'))->assertOk();
    $this->get(route('employees.calendar', $cleaner))->assertOk();

    $calendar = Livewire::test('profile.page-calendar', ['employee' => $cleaner->id]);
    $permission->update(['can_view_all_calendars' => false]);
    $calendar->call('nextMonth')->assertForbidden();
});

it('rejects changing the employee on a hydrated calendar', function () {
    $admin = createCalendarPageUser('admin', 'Администратор календаря');
    $supervisor = createCalendarPageUser('supervisor', 'Другой сотрудник');

    Livewire::actingAs($admin)
        ->test('profile.page-calendar', ['employee' => 21])
        ->set('employeeId', $supervisor->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('protects employee calendar data during role preview', function () {
    $admin = createCalendarPageUser('admin', 'Администратор календаря');
    $this->actingAs($admin);
    $calendar = Livewire::test('profile.page-calendar', ['employee' => 21]);

    $this->withSession([RolePreviewContext::SESSION_KEY => 'cleaner']);
    $this->get(route('page-profile'))->assertOk()->assertDontSee('Календари сотрудников');
    $this->get(route('page-profile.employee-calendars'))->assertForbidden();
    $this->get(route('employees.calendar', ['employee' => 21]))->assertForbidden();
    $this->get(route('page-profile'))->assertOk()->assertDontSee('Моя статистика');
    $calendar->call('nextMonth')->assertForbidden();
});

function createCalendarPageUser(string $role, string $name, bool $active = true, string $status = 'approved'): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'role' => $role,
        'status' => $status,
        'is_active' => $active,
        'birthday' => '1990-02-03',
        'work_started_at' => '2020-04-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

it('renders personal statistics at the profile entry point and switches the selected month', function () {
    $employee = User::findOrFail(21);

    $this->actingAs($employee)
        ->get(route('page-profile'))
        ->assertOk()
        ->assertSee('Моя статистика')
        ->assertSee('Ближайший рабочий день');

    Livewire::actingAs($employee)->test('profile.personal-statistics')
        ->assertSet('month', '2026-11')
        ->call('selectDay', '2026-11-05')
        ->assertSee('Рабочий день по графику')
        ->call('changeMonth', -1)
        ->assertSet('month', '2026-10')
        ->assertSee('График не зафиксирован')
        ->assertSee('Ближайший рабочий день');
});

it('combines employee-owned activity, application statuses, learning and Mare without leaking another employee data', function () {
    $employee = User::findOrFail(21);
    $other = createCalendarPageUser('cleaner', 'Чужой профиль');
    $supervisor = createCalendarPageUser('supervisor', 'Автор контроля');

    foreach (['controls', 'apartments'] as $name) {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
    $apartment = DB::table('apartments')->insertGetId(['name' => 'Orti 12']);
    $otherApartment = DB::table('apartments')->insertGetId(['name' => 'Чужая квартира']);
    foreach ([
        '2026_04_20_185550_create_control_responses_table.php',
        '2026_06_09_215456_add_control_score_details_to_control_responses_table.php',
        '2026_10_24_000000_create_coachings_table.php',
        '2026_04_27_033156_create_instruction_categories_table.php',
        '2026_04_27_033240_create_instructions_table.php',
        '2026_11_15_000100_create_learning_lesson_progress_table.php',
        '2026_06_12_210833_create_tris_mare_snapshots_table.php',
    ] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }
    $control = DB::table('controls')->insertGetId(['name' => 'Контроль качества']);
    foreach ([
        [$employee->id, $apartment, '2026-11-07', 92],
        [$employee->id, $apartment, '2026-10-07', 30],
        [$other->id, $otherApartment, '2026-11-07', 42],
    ] as [$cleaner, $flat, $date, $score]) {
        DB::table('control_responses')->insert([
            'control_id' => $control, 'cleaner_id' => $cleaner, 'supervisor_id' => $supervisor->id,
            'apartment_id' => $flat, 'inspection_date' => $date, 'score_percent' => $score,
            'total_points' => $score, 'max_points' => 100, 'result_zone' => 'yellow', 'errors_count' => 2, 'penalty_points' => 3,
        ]);
    }
    $novemberControl = DB::table('control_responses')->where('cleaner_id', $employee->id)->where('inspection_date', '2026-11-07')->value('id');
    $coaching = DB::table('coachings')->insertGetId([
        'user_id' => $employee->id, 'supervisor_id' => $supervisor->id, 'apartment_id' => $apartment,
        'inspection_date' => '2026-11-07', 'reason' => 'Проверка ванной',
    ]);
    foreach (['inventory_requests', 'salary_questions', 'schedule_questions', 'feedback_suggestions'] as $tableName) {
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('status');
        });
    }
    DB::table('day_off_requests')->insert(['user_id' => $employee->id, 'status' => 'partially_approved']);
    $vacation = DB::table('vacation_requests')->insertGetId(['user_id' => $employee->id, 'status' => 'partially_approved']);
    DB::table('vacation_request_days')->insert([
        ['vacation_request_id' => $vacation, 'user_id' => $employee->id, 'date' => '2026-11-11', 'status' => 'approved'],
        ['vacation_request_id' => $vacation, 'user_id' => $employee->id, 'date' => '2026-11-12', 'status' => 'pending'],
    ]);
    DB::table('salary_questions')->insert(['user_id' => $other->id, 'status' => 'rejected']);
    $lesson = DB::table('instructions')->insertGetId(['title' => 'Первый урок', 'slug' => 'first', 'status' => 'published', 'is_public' => true]);
    DB::table('instructions')->insert(['title' => 'Второй урок', 'slug' => 'next', 'status' => 'published', 'is_public' => true]);
    DB::table('learning_lesson_progress')->insert([
        'user_id' => $employee->id, 'instruction_id' => $lesson, 'last_opened_at' => now(), 'completed_at' => now(),
    ]);
    DB::table('tris_mare_snapshots')->insert([
        ['user_id' => $employee->id, 'employee_name' => $employee->name, 'total_points' => 320, 'rating' => 2, 'daily_history' => json_encode([['date' => '03.08.2026', 'points' => 10, 'comment' => 'Смена']])],
        ['user_id' => $other->id, 'employee_name' => $other->name, 'total_points' => 999, 'rating' => 1, 'daily_history' => null],
    ]);

    try {
        $stats = Livewire::actingAs($employee)->test('profile.personal-statistics')
            ->assertSee('Частично одобрено · 2')->assertDontSee('Отклонено ·')
            ->assertSee('Уроки · 1 из 2 · 50%')->assertSee('Второй урок')
            ->assertSee('TRIS Mare 2026 · 320 баллов')->assertSee('03.08.2026')->assertDontSee('999 баллов')
            ->call('selectDay', '2026-11-11')->assertSee('Одобренный отпуск')
            ->call('selectDay', '2026-11-12')->assertSee('Есть заявка на согласовании')
            ->call('chooseMode', 'quality')
            ->assertSee('Средний результат · 92%')->assertSee('Ошибки · 2')->assertSee('Штрафные баллы · 3')
            ->assertSee('Контроль и коучинг')->assertSee('Orti 12')->assertSee('Автор контроля')
            ->assertDontSee('Чужая квартира')->assertDontSee('Средний результат · 30%')
            ->assertSee(route('page-coachings.show', [$coaching, 'stats_month' => '2026-11', 'stats_view' => 'quality', 'stats_day' => '2026-11-07']));

        $stats->call('changeMonth', -1)->assertSee('Средний результат · 30%');

        $this->get(route('page-profile.calendar', ['month' => '2026-10', 'day' => '2026-10-17']))
            ->assertOk()->assertSee('17 октября 2026');
        $this->get(route('page-profile.calendar', ['month' => '2026-10', 'day' => ['2026-10-17']]))
            ->assertOk()->assertSee('1 октября 2026');

        $returnState = ['stats_month' => '2026-11', 'stats_view' => 'quality', 'stats_day' => '2026-11-07'];
        $this->get(route('page-coachings.show', [$coaching, ...$returnState]))
            ->assertOk()->assertSee('Назад к статистике')->assertSee('#profile-statistics-title');
        $this->get(route('page-profile.checks.result', [$novemberControl, ...$returnState]))
            ->assertOk()->assertSee('Назад к статистике')->assertSee('#profile-statistics-title');

        $this->get(route('page-profile', [...$returnState, 'stats_month' => '2026-10', 'stats_day' => '2026-10-07']))
            ->assertOk()->assertSee('Средний результат · 30%')->assertSee('Orti 12');

    } finally {
        foreach (['tris_mare_snapshots', 'learning_lesson_progress', 'instructions', 'instruction_categories',
            'feedback_suggestions', 'schedule_questions', 'salary_questions', 'inventory_requests', 'coachings',
            'control_responses', 'apartments', 'controls',
        ] as $name) {
            Schema::dropIfExists($name);
        }
    }
});

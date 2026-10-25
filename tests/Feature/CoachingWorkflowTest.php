<?php

use App\Models\Coaching;
use App\Models\User;
use App\Services\UserApplicationBadgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.key' => str_repeat('t', 32)]);
    $this->withoutVite();
    $this->mock(UserApplicationBadgeService::class, function ($mock) {
        $mock->shouldReceive('label')->andReturnNull();
    });
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
    foreach (['coachings', 'controls', 'apartments', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('controls', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function () {
    foreach (['coachings', 'controls', 'apartments', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function coachingMigration(): object
{
    $path = database_path('migrations/2026_10_24_000000_create_coachings_table.php');

    expect(is_file($path))->toBeTrue("Migration file not found at {$path}");

    return require $path;
}

it('creates the verified legacy schema and maps it through coaching relationships and aliases', function () {
    expect(class_exists(Coaching::class))->toBeTrue();

    coachingMigration()->up();

    expect(Schema::getColumnListing('coachings'))->toBe([
        'id', 'user_id', 'supervisor_id', 'control_id', 'apartment_id',
        'cleaning_date', 'inspection_date', 'is_assigned', 'previous_cleaner',
        'control_response_ids', 'reason', 'status', 'sent_at', 'created_at', 'updated_at',
    ]);

    $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(coachings)'))
        ->map(fn (object $foreignKey): array => [$foreignKey->from, $foreignKey->table, $foreignKey->to, $foreignKey->on_delete])
        ->all();

    expect($foreignKeys)->toContain(
        ['user_id', 'users', 'id', 'CASCADE'],
        ['supervisor_id', 'users', 'id', 'SET NULL'],
        ['control_id', 'controls', 'id', 'SET NULL'],
        ['apartment_id', 'apartments', 'id', 'SET NULL'],
    );

    $cleaner = User::create(['name' => 'Cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $supervisor = User::create(['name' => 'Supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $controlId = DB::table('controls')->insertGetId([]);
    $apartmentId = DB::table('apartments')->insertGetId([]);

    $coachingId = DB::table('coachings')->insertGetId([
        'user_id' => $cleaner->id,
        'supervisor_id' => $supervisor->id,
        'control_id' => $controlId,
        'apartment_id' => $apartmentId,
        'inspection_date' => '2026-10-24',
        'reason' => 'Провели разбор порядка уборки.',
    ]);

    $coaching = Coaching::query()->findOrFail($coachingId);

    expect($coaching->cleaner->is($cleaner))->toBeTrue()
        ->and($coaching->supervisor->is($supervisor))->toBeTrue()
        ->and($coaching->control->getKey())->toBe($controlId)
        ->and($coaching->apartment->getKey())->toBe($apartmentId)
        ->and($coaching->conducted_date->toDateString())->toBe('2026-10-24')
        ->and($coaching->description)->toBe('Провели разбор порядка уборки.');

    $defaultId = DB::table('coachings')->insertGetId(['user_id' => $cleaner->id]);
    $defaults = DB::table('coachings')->where('id', $defaultId)->first();

    expect((int) $defaults->is_assigned)->toBe(0)
        ->and($defaults->status)->toBe('sent');
});

it('leaves a pre-existing legacy coaching table and its data untouched', function () {
    Schema::create('coachings', function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_sentinel');
    });

    DB::table('coachings')->insert(['legacy_sentinel' => 'preserve-me']);
    $columnsBefore = Schema::getColumnListing('coachings');

    coachingMigration()->up();

    expect(Schema::getColumnListing('coachings'))->toBe($columnsBefore)
        ->and(DB::table('coachings')->value('legacy_sentinel'))->toBe('preserve-me');
});

function coachingWorkflowUser(string $name, string $role): User
{
    return User::create([
        'name' => $name,
        'role' => $role,
        'status' => 'approved',
        'is_active' => true,
    ]);
}

function coachingWorkflowRecord(User $cleaner, User $supervisor, string $date, string $description): int
{
    coachingMigration()->up();

    return DB::table('coachings')->insertGetId([
        'user_id' => $cleaner->id,
        'supervisor_id' => $supervisor->id,
        'inspection_date' => $date,
        'reason' => $description,
    ]);
}

it('registers entry, history, create, and detail routes for coaching', function () {
    expect(Route::has('page-coachings'))->toBeTrue()
        ->and(Route::has('page-coachings.history'))->toBeTrue()
        ->and(Route::has('page-coachings.create'))->toBeTrue()
        ->and(Route::has('page-coachings.show'))->toBeTrue();
});

it('links the existing coaching tile to the working list', function () {
    $user = coachingWorkflowUser('Cleaner', 'cleaner');

    Livewire::actingAs($user)
        ->test('page-checks')
        ->assertSee(route('page-coachings'), false);
});

it('opens the creation form directly for managers and history for cleaners', function (string $role) {
    coachingMigration()->up();
    $user = coachingWorkflowUser('Entry user', $role);
    $destination = $role === 'cleaner' ? 'page-coachings.history' : 'page-coachings.create';

    $this->actingAs($user)->get(route('page-coachings'))->assertRedirect(route($destination));
    $this->get(route($destination))->assertOk();
})->with(['admin', 'supervisor', 'cleaner']);

it('lets a manager create one coaching attributed to the authenticated author and open its card', function (string $role) {
    coachingMigration()->up();
    $supervisor = coachingWorkflowUser('Manager', $role);
    $cleaner = coachingWorkflowUser('Cleaner', 'cleaner');

    Livewire::actingAs($supervisor)
        ->test('forms.page-coaching-create')
        ->set('cleanerId', $cleaner->id)
        ->set('conductedDate', '2026-10-24')
        ->set('description', 'Поговорили о порядке подготовки квартиры к заезду.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('page-coachings.show', Coaching::query()->sole()));

    $record = DB::table('coachings')->sole();

    expect((int) $record->user_id)->toBe($cleaner->id)
        ->and((int) $record->supervisor_id)->toBe($supervisor->id)
        ->and(Carbon::parse($record->inspection_date)->toDateString())->toBe('2026-10-24')
        ->and($record->reason)->toBe('Поговорили о порядке подготовки квартиры к заезду.');
})->with(['admin', 'supervisor']);

it('requires a cleaner, conducted date, and narrative when creating a coaching', function () {
    coachingMigration()->up();
    $supervisor = coachingWorkflowUser('Supervisor', 'supervisor');

    Livewire::actingAs($supervisor)
        ->test('forms.page-coaching-create')
        ->set('conductedDate', '')
        ->call('save')
        ->assertHasErrors(['cleanerId', 'conductedDate', 'description']);

    expect(DB::table('coachings')->count())->toBe(0);
});

it('rejects inactive or non-cleaner users as coaching recipients', function () {
    coachingMigration()->up();
    $supervisor = coachingWorkflowUser('Supervisor', 'supervisor');
    $inactive = coachingWorkflowUser('Inactive', 'cleaner');
    $inactive->update(['is_active' => false]);
    $otherSupervisor = coachingWorkflowUser('Other supervisor', 'supervisor');

    Livewire::actingAs($supervisor)
        ->test('forms.page-coaching-create')
        ->set('cleanerId', $inactive->id)
        ->set('conductedDate', '2026-10-24')
        ->set('description', 'Обсудили рабочий процесс.')
        ->call('save')
        ->assertHasErrors('cleanerId');

    Livewire::actingAs($supervisor)
        ->test('forms.page-coaching-create')
        ->set('cleanerId', $otherSupervisor->id)
        ->set('conductedDate', '2026-10-24')
        ->set('description', 'Обсудили рабочий процесс.')
        ->call('save')
        ->assertHasErrors('cleanerId');

    expect(DB::table('coachings')->count())->toBe(0);
});

it('does not duplicate a coaching when the same form is submitted twice', function () {
    coachingMigration()->up();
    $supervisor = coachingWorkflowUser('Supervisor', 'supervisor');
    $cleaner = coachingWorkflowUser('Cleaner', 'cleaner');
    $form = Livewire::actingAs($supervisor)
        ->test('forms.page-coaching-create')
        ->set('cleanerId', $cleaner->id)
        ->set('conductedDate', '2026-10-24')
        ->set('description', 'Обсудили рабочий процесс.');

    $form->call('save')->call('save');

    expect(DB::table('coachings')->count())->toBe(1)
        ->and((int) DB::table('coachings')->value('supervisor_id'))->toBe($supervisor->id);
});

it('shows supervisors only their coachings and cleaners only their own', function () {
    coachingMigration()->up();
    $supervisorA = coachingWorkflowUser('Supervisor A', 'supervisor');
    $supervisorB = coachingWorkflowUser('Supervisor B', 'supervisor');
    $cleanerA = coachingWorkflowUser('Cleaner A', 'cleaner');
    $cleanerB = coachingWorkflowUser('Cleaner B', 'cleaner');
    coachingWorkflowRecord($cleanerA, $supervisorA, '2026-10-22', 'Уникальный разбор A');
    coachingWorkflowRecord($cleanerB, $supervisorB, '2026-10-23', 'Уникальный разбор B');

    Livewire::actingAs($supervisorA)
        ->test('page-coachings')
        ->assertSee('Уникальный разбор A')
        ->assertDontSee('Уникальный разбор B');

    Livewire::actingAs($cleanerA)
        ->test('page-coachings')
        ->assertSee('Уникальный разбор A')
        ->assertDontSee('Уникальный разбор B');
});

it('lets administrators filter all coachings by cleaner and date', function () {
    coachingMigration()->up();
    $admin = coachingWorkflowUser('Admin', 'admin');
    $supervisor = coachingWorkflowUser('Supervisor', 'supervisor');
    $cleanerA = coachingWorkflowUser('Cleaner A', 'cleaner');
    $cleanerB = coachingWorkflowUser('Cleaner B', 'cleaner');
    coachingWorkflowRecord($cleanerA, $supervisor, '2026-10-22', 'Админский разбор A');
    coachingWorkflowRecord($cleanerB, $supervisor, '2026-10-23', 'Админский разбор B');

    Livewire::actingAs($admin)
        ->test('page-coachings')
        ->assertSee('Админский разбор A')
        ->assertSee('Админский разбор B')
        ->set('employeeFilter', $cleanerA->id)
        ->assertSee('Админский разбор A')
        ->assertDontSee('Админский разбор B')
        ->set('employeeFilter', null)
        ->set('dateFrom', '2026-10-23')
        ->assertDontSee('Админский разбор A')
        ->assertSee('Админский разбор B');
});

it('allows an administrator, the authoring supervisor, and the cleaner to view a coaching but blocks other staff', function () {
    coachingMigration()->up();
    $admin = coachingWorkflowUser('Admin', 'admin');
    $supervisor = coachingWorkflowUser('Supervisor', 'supervisor');
    $otherSupervisor = coachingWorkflowUser('Other supervisor', 'supervisor');
    $cleaner = coachingWorkflowUser('Cleaner', 'cleaner');
    $otherCleaner = coachingWorkflowUser('Other cleaner', 'cleaner');
    $coachingId = coachingWorkflowRecord($cleaner, $supervisor, '2026-10-24', 'Приватный разбор сотрудника');
    $url = route('page-coachings.show', $coachingId);

    $this->actingAs($admin)->get($url)->assertOk()->assertSee('Приватный разбор сотрудника');
    $this->actingAs($supervisor)->get($url)->assertOk();
    $this->actingAs($cleaner)->get($url)->assertOk();
    $this->actingAs($otherSupervisor)->get($url)->assertForbidden();
    $this->actingAs($otherCleaner)->get($url)->assertForbidden();
});

it('shows a clear empty state for a staff member with no coachings', function () {
    coachingMigration()->up();
    $cleaner = coachingWorkflowUser('Cleaner', 'cleaner');

    Livewire::actingAs($cleaner)
        ->test('page-coachings')
        ->assertSee('Пока нет коучингов');
});

it('blocks coaching creation by cleaners', function () {
    coachingMigration()->up();
    $user = coachingWorkflowUser('Cleaner', 'cleaner');
    Livewire::actingAs($user)->test('forms.page-coaching-create')->assertForbidden();
    $this->actingAs($user)->get(route('page-coachings.create'))->assertForbidden();
    expect(DB::table('coachings')->count())->toBe(0);
});

it('defaults to today and retains an earlier date and cleaner after validation errors', function () {
    coachingMigration()->up();
    $admin = coachingWorkflowUser('Admin', 'admin');
    $cleaner = coachingWorkflowUser('Cleaner', 'cleaner');

    Livewire::actingAs($admin)->test('forms.page-coaching-create')
        ->assertSet('conductedDate', '2026-10-24')
        ->assertSee(route('page-coachings.history'), false)
        ->set('cleanerId', $cleaner->id)
        ->set('conductedDate', '2026-10-20')
        ->set('description', '')
        ->call('save')
        ->assertHasErrors('description')
        ->assertSet('cleanerId', $cleaner->id)
        ->assertSet('conductedDate', '2026-10-20')
        ->set('description', 'Отработали последовательность уборки.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Coaching::query()->sole()->conducted_date->toDateString())->toBe('2026-10-20');
});

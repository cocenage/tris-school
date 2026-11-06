<?php

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\RewardPrograms\Pages\EditRewardProgram;
use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\BlockRolePreviewWrites;
use App\Http\Middleware\EndRolePreviewOnFilamentAccess;
use App\Models\Apartment;
use App\Models\CalendarEvent;
use App\Models\Control;
use App\Models\DayOffRequest;
use App\Models\RewardProgram;
use App\Models\User;
use App\Services\RolePreviewContext;
use App\Services\UserApplicationBadgeService;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.sqlite.url' => null,
        'cache.default' => 'array',
    ]);
    DB::purge('sqlite');

    Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->date('birthday')->nullable();
        $table->date('work_started_at')->nullable();
        $table->string('telegram_photo_url')->nullable();
        $table->string('telegram_avatar_path')->nullable();
        $table->json('panel_access')->nullable();
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('user_panel_accesses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('panel');
        $table->boolean('can_access')->default(false);
        $table->timestamps();
        $table->unique(['user_id', 'panel']);
    });

    Schema::connection('sqlite')->create('user_calendar_type_accesses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type');
        $table->boolean('can_view')->default(false);
        $table->timestamps();
        $table->unique(['user_id', 'type']);
    });

    Schema::connection('sqlite')->create('user_calendar_permissions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->boolean('can_view_all_calendars')->default(false);
        $table->boolean('can_edit')->default(false);
        $table->boolean('can_export')->default(false);
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('calendar_events', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('type')->default('workflow');
        $table->date('start_date');
        $table->date('end_date')->nullable();
        $table->string('repeat_type')->default('none');
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('priority')->default(0);
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->string('information_status')->default('published');
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('apartment_user_access', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->unsignedBigInteger('user_id');
        $table->dateTime('expires_at')->nullable();
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('day_off_requests', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('day_off_request_days', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('day_off_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('vacation_requests', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    Schema::connection('sqlite')->create('vacation_request_days', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('vacation_request_id');
        $table->date('date');
        $table->timestamps();
    });

    $this->admin = User::query()->forceCreate([
        'name' => 'Test Administrator',
        'email' => 'admin@example.test',
        'password' => 'unused',
        'role' => 'admin',
        'status' => 'approved',
        'is_active' => true,
    ]);

    $this->cleaner = User::query()->forceCreate([
        'name' => 'Test Cleaner',
        'email' => 'cleaner@example.test',
        'password' => 'unused',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
    ]);

    $this->supervisor = User::query()->forceCreate([
        'name' => 'Test Supervisor',
        'email' => 'supervisor@example.test',
        'password' => 'unused',
        'role' => 'supervisor',
        'status' => 'approved',
        'is_active' => true,
    ]);

    $this->app->instance(UserApplicationBadgeService::class, new class extends UserApplicationBadgeService
    {
        public function label(?int $userId): ?string
        {
            return null;
        }
    });
});

afterEach(function () {
    DB::purge('sqlite');
});

it('keeps the authenticated admin role separate from the session UI preview', function () {
    $this->actingAs($this->admin);
    $context = app(RolePreviewContext::class);

    expect($context->actualRole())->toBe('admin')
        ->and($context->effectiveUiRole())->toBe('admin');

    $context->start('supervisor');

    expect($context->actualRole())->toBe('admin')
        ->and($context->previewRole())->toBe('supervisor')
        ->and($context->effectiveUiRole())->toBe('supervisor')
        ->and($this->admin->fresh()->role)->toBe('admin')
        ->and($this->admin->isAdmin())->toBeTrue()
        ->and($this->admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    $context->start('cleaner');

    expect($context->effectiveUiRole())->toBe('cleaner');

    $context->start('admin');

    expect($context->isActive())->toBeFalse()
        ->and($context->effectiveUiRole())->toBe('admin');
});

it('allows only an approved real admin to change the role preview session', function () {
    $this->actingAs($this->admin)
        ->post(route('role-preview.store'), ['role' => 'supervisor'])
        ->assertRedirect(route('page-profile'));

    expect(session(RolePreviewContext::SESSION_KEY))->toBe('supervisor');

    $this->actingAs($this->cleaner)
        ->post(route('role-preview.store'), ['role' => 'admin'])
        ->assertForbidden();

    $this->actingAs($this->supervisor)
        ->post(route('role-preview.store'), ['role' => 'cleaner'])
        ->assertForbidden();

    expect($this->cleaner->fresh()->role)->toBe('cleaner');
});

it('lets the real admin exit preview and returns the UI to the real role', function () {
    $this->actingAs($this->admin);
    app(RolePreviewContext::class)->start('cleaner');

    $this->post(route('role-preview.clear'))
        ->assertRedirect(route('page-profile'));

    expect(session()->has(RolePreviewContext::SESSION_KEY))->toBeFalse()
        ->and(app(RolePreviewContext::class)->effectiveUiRole())->toBe('admin')
        ->and($this->admin->fresh()->role)->toBe('admin');
});

it('exits preview when the real admin enters Filament and can save a record there', function () {
    (require database_path('migrations/2026_06_10_201258_create_reward_programs_table.php'))->up();

    $program = RewardProgram::query()->create([
        'name' => 'Preview transition test',
        'is_active' => true,
    ]);

    $authMiddleware = Filament::getPanel('admin')->getAuthMiddleware();
    $authenticateIndex = array_search(Authenticate::class, $authMiddleware, true);
    $exitPreviewIndex = array_search(EndRolePreviewOnFilamentAccess::class, $authMiddleware, true);

    expect($exitPreviewIndex)->toBeGreaterThan($authenticateIndex);

    $this->actingAs($this->admin);
    app(RolePreviewContext::class)->start('cleaner');

    $response = app(EndRolePreviewOnFilamentAccess::class)->handle(
        Request::create('/admin/reward-programs/'.$program->getKey().'/edit'),
        fn () => response()->noContent(),
    );

    expect($response->getStatusCode())->toBe(204)
        ->and(app(RolePreviewContext::class)->isActive())->toBeFalse()
        ->and($this->admin->fresh()->role)->toBe('admin')
        ->and($this->admin->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($this->admin)
        ->test(EditRewardProgram::class, ['record' => $program->getKey()])
        ->fillForm(['name' => 'Updated by Filament'])
        ->call('save')
        ->assertHasNoErrors();

    expect($program->fresh()->name)->toBe('Updated by Filament');
});

it('blocks form updates and allows explicit read navigation', function () {
    $guard = app(BlockRolePreviewWrites::class);
    $component = fn (string $name, array $updates = [], array $calls = []): array => [
        'snapshot' => json_encode(['memo' => ['name' => $name]], JSON_THROW_ON_ERROR),
        'updates' => $updates,
        'calls' => $calls,
    ];

    expect($guard->containsWriteOperation([
        $component('forms.page-weekend', [], [['method' => 'submit', 'params' => []]]),
    ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('forms.page-control', ['answers.0.0.value' => 'yes']),
        ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('tasks.page-calendar', [], [['method' => 'openDay', 'params' => ['2026-10-25']]]),
        ]))->toBeFalse();
});

it('fails closed for unlisted Livewire actions, malformed components, and Filament mutations', function () {
    $guard = app(BlockRolePreviewWrites::class);
    $component = fn (string $name, array $updates = [], array $calls = []): array => [
        'snapshot' => json_encode(['memo' => ['name' => $name]], JSON_THROW_ON_ERROR),
        'updates' => $updates,
        'calls' => $calls,
    ];

    expect($guard->containsWriteOperation([
        $component('forms.page-weekend', [], [['method' => 'futureMutation', 'params' => []]]),
    ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('future.application-component', [], [['method' => 'saveLater', 'params' => []]]),
        ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('future.application-component', ['state' => 'changed']),
        ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('tasks.page-calendar', [], [['method' => 'openDay', 'params' => ['2026-10-25']]]),
        ]))->toBeFalse()
        ->and($guard->containsWriteOperation([
            $component('app.filament.resources.users.pages.edit-user', [], [['method' => 'save', 'params' => []]]),
        ]))->toBeTrue()
        ->and($guard->containsWriteOperation([
            $component('app.filament.pages.dashboard', ['form.name' => 'changed']),
        ]))->toBeTrue()
        ->and($guard->containsWriteOperation([new stdClass]))->toBeTrue();
});

it('installs the guard on Livewire updates and blocks writes without blocking ordinary page reads', function () {
    $updateRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->getName() === 'role-preview.livewire.update');

    expect($updateRoute)->not->toBeNull()
        ->and($updateRoute->gatherMiddleware())->toContain(BlockRolePreviewWrites::class);

    $this->actingAs($this->admin);
    app(RolePreviewContext::class)->start('cleaner');

    $request = Request::create('/livewire/update', 'POST');
    expect(fn () => app(BlockRolePreviewWrites::class)->handle($request, fn () => response()->noContent()))
        ->toThrow(HttpException::class);

    $request->request->set('components', [[
        'snapshot' => json_encode(['memo' => ['name' => 'forms.page-weekend']], JSON_THROW_ON_ERROR),
        'updates' => [],
        'calls' => [['method' => 'submit', 'params' => []]],
    ]]);

    expect(fn () => app(BlockRolePreviewWrites::class)->handle($request, fn () => response()->noContent()))
        ->toThrow(HttpException::class);

    $request->request->set('components', [[
        'snapshot' => json_encode(['memo' => ['name' => 'tasks.page-calendar']], JSON_THROW_ON_ERROR),
        'updates' => [],
        'calls' => [['method' => 'openDay', 'params' => ['2026-10-25']]],
    ]]);

    expect(app(BlockRolePreviewWrites::class)
        ->handle($request, fn () => response()->noContent())
        ->getStatusCode())->toBe(204);
});

it('does not allow a preview session to reach a persistence action', function () {
    $this->actingAs($this->admin);
    app(RolePreviewContext::class)->start('cleaner');

    expect(fn () => app(RolePreviewContext::class)->assertWritable())
        ->toThrow(HttpException::class);
});

it('shows the preview selector only to the actual admin', function () {
    Livewire::actingAs($this->admin)
        ->test('page-profile')
        ->assertSee('Режим просмотра');

    Livewire::actingAs($this->cleaner)
        ->test('page-profile')
        ->assertDontSee('Режим просмотра');
});

it('changes the visible profile interface during preview without changing the real admin role', function () {
    $this->actingAs($this->admin);

    Livewire::test('page-profile')->assertSee('Администрирование');

    app(RolePreviewContext::class)->start('cleaner');

    Livewire::test('page-profile')
        ->assertSee('Мои контроли')
        ->assertDontSee('Администрирование');

    expect($this->admin->fresh()->role)->toBe('admin')
        ->and($this->admin->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('shows a safe empty state instead of employee records during a role preview', function () {
    $this->actingAs($this->admin)
        ->withSession([RolePreviewContext::SESSION_KEY => 'cleaner'])
        ->get(route('page-checks.control'))
        ->assertOk()
        ->assertSee('Предпросмотр: Клинер')
        ->assertSee('Выйти из предпросмотра')
        ->assertSee('Данные сотрудников скрыты');
});

it('renames the education panel without widening its explicit access grants', function () {
    DB::table('user_panel_accesses')->insert([
        'user_id' => $this->supervisor->id,
        'panel' => 'education',
        'can_access' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->supervisor->canAccessPanel(Filament::getPanel('education')))->toBeTrue()
        ->and($this->cleaner->canAccessPanel(Filament::getPanel('education')))->toBeFalse();

    $this->actingAs($this->supervisor)->get('/admin/supervisor')->assertOk();
    $this->actingAs($this->admin)->get('/admin/education')->assertRedirect('/admin/supervisor');
    $this->actingAs($this->supervisor)->get('/admin/education/users/'.$this->supervisor->id.'/edit')
        ->assertRedirect('/admin/supervisor/users/'.$this->supervisor->id.'/edit');
    $this->actingAs($this->cleaner)->get('/admin/supervisor')->assertForbidden();
    $this->actingAs($this->cleaner)->get('/admin/education/users/'.$this->supervisor->id.'/edit')->assertForbidden();
});

it('blocks supervisor user administration and unsafe resource actions server-side', function () {
    Filament::setCurrentPanel('education');
    DB::table('user_panel_accesses')->insert([
        'user_id' => $this->supervisor->id,
        'panel' => 'education',
        'can_access' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_calendar_type_accesses')->insert([
        [
            'user_id' => $this->supervisor->id,
            'type' => 'workflow',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $this->supervisor->id,
            'type' => 'finance',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs($this->supervisor)
        ->get('/admin/supervisor/users/'.$this->supervisor->id.'/edit')
        ->assertForbidden();

    expect(Gate::forUser($this->supervisor)->allows('review', new DayOffRequest))
        ->toBeTrue()
        ->and(Gate::forUser($this->cleaner)->allows('review', new DayOffRequest))->toBeFalse()
        ->and(Gate::forUser($this->supervisor)->allows('update', new Control))->toBeFalse()
        ->and(Gate::forUser($this->supervisor)->allows('create', Control::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', new Control))->toBeTrue()
        ->and(Gate::forUser($this->supervisor)->allows('manageAccess', new Apartment))->toBeFalse()
        ->and(Gate::forUser($this->supervisor)->allows('view', new CalendarEvent(['type' => 'workflow'])))->toBeTrue()
        ->and(Gate::forUser($this->supervisor)->allows('view', new CalendarEvent(['type' => 'finance'])))->toBeFalse();

    $this->actingAs($this->supervisor)
        ->get('/admin/supervisor/controls/create')
        ->assertForbidden();
});

it('limits supervisor staff and calendar queries to approved safe records and permitted categories', function () {
    Filament::setCurrentPanel('education');
    DB::table('user_panel_accesses')->insert([
        'user_id' => $this->supervisor->id,
        'panel' => 'education',
        'can_access' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_calendar_type_accesses')->insert([
        [
            'user_id' => $this->supervisor->id,
            'type' => 'workflow',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $this->supervisor->id,
            'type' => 'finance',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    DB::table('calendar_events')->insert([
        ['title' => 'Рабочее событие', 'type' => 'workflow', 'start_date' => '2026-11-07', 'is_active' => true, 'priority' => 0, 'repeat_type' => 'none'],
        ['title' => 'Финансовое событие', 'type' => 'finance', 'start_date' => '2026-11-07', 'is_active' => true, 'priority' => 0, 'repeat_type' => 'none'],
    ]);
    $inactiveCleaner = User::query()->forceCreate([
        'name' => 'Inactive cleaner',
        'email' => 'inactive@example.test',
        'password' => 'secret',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => false,
    ]);
    $pendingSupervisor = User::query()->forceCreate([
        'name' => 'Pending supervisor',
        'email' => 'pending@example.test',
        'password' => 'secret',
        'role' => 'supervisor',
        'status' => 'pending',
        'is_active' => true,
    ]);

    auth()->login($this->supervisor);

    $tabs = (new ListCalendarEvents)->getTabs();

    expect(array_keys($tabs))->not->toContain('finance')
        ->and(CalendarEventResource::getNavigationBadge())->toBe('1')
        ->and(CalendarEventResource::getEloquentQuery()->pluck('type')->all())
        ->toBe(['workflow'])
        ->and(SupervisorEmployeeResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($this->cleaner->id)
        ->not->toContain($inactiveCleaner->id)
        ->not->toContain($pendingSupervisor->id)
        ->and(UserResource::canViewAny())->toBeFalse();

    Filament::setCurrentPanel('admin');
    expect(SupervisorEmployeeResource::canViewAny())->toBeFalse();
});

it('shows profile links by actual access and excludes finance calendar events for supervisors', function () {
    DB::table('user_panel_accesses')->insert([
        'user_id' => $this->supervisor->id,
        'panel' => 'education',
        'can_access' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_calendar_type_accesses')->insert([
        [
            'user_id' => $this->supervisor->id,
            'type' => 'workflow',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $this->supervisor->id,
            'type' => 'finance',
            'can_view' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Livewire::actingAs($this->supervisor)
        ->test('page-profile')
        ->assertSee('Мой календарь')
        ->assertSee('Рабочий календарь')
        ->assertSee('Панель супервайзера')
        ->assertSee('/admin/supervisor')
        ->assertDontSee('/admin/finance');

    Livewire::actingAs($this->cleaner)
        ->test('page-profile')
        ->assertSee('Мой календарь')
        ->assertDontSee('Рабочий календарь')
        ->assertDontSee('Панель супервайзера')
        ->assertDontSee('/admin/finance');

    Livewire::actingAs($this->admin)
        ->test('page-profile')
        ->assertSee('Рабочий календарь')
        ->assertDontSee('/admin/finance');

    DB::table('user_calendar_type_accesses')->where('user_id', $this->supervisor->id)->where('type', 'workflow')->delete();
    Livewire::actingAs($this->supervisor)->test('page-profile')->assertDontSee('Рабочий календарь');
});

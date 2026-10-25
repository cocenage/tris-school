<?php

use App\Http\Middleware\BlockRolePreviewWrites;
use App\Models\User;
use App\Services\RolePreviewContext;
use App\Services\UserApplicationBadgeService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
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

    Schema::connection('sqlite')->create('calendar_events', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->date('start_date');
        $table->date('end_date')->nullable();
        $table->string('repeat_type')->default('none');
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('priority')->default(0);
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

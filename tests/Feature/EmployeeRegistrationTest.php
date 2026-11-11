<?php

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\TelegramMiniAppService;
use App\Services\TelegramUserNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->date('birthday')->nullable();
        $table->date('work_started_at')->nullable();
        $table->string('telegram_id')->nullable()->unique();
        $table->string('telegram_username')->nullable();
        $table->string('telegram_first_name')->nullable();
        $table->string('telegram_last_name')->nullable();
        $table->string('telegram_photo_url')->nullable();
        $table->timestamp('telegram_last_auth_at')->nullable();
        $table->string('telegram_login_source')->nullable();
        $table->timestamp('telegram_write_access_granted_at')->nullable();
        $table->timestamp('telegram_access_requested_notified_at')->nullable();
        $table->timestamp('telegram_access_pending_notified_at')->nullable();
        $table->timestamp('telegram_access_approved_notified_at')->nullable();
        $table->timestamp('last_login_at')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->unsignedBigInteger('approved_by')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('pending');
        $table->boolean('is_active')->default(true);
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    (require database_path('migrations/2026_11_11_000002_add_registration_draft_to_users_table.php'))->up();
});

afterEach(function () {
    Schema::dropIfExists('users');
});

function registrationTelegramLogin(array $telegramUser, int $times = 1): void
{
    $miniApp = Mockery::mock(TelegramMiniAppService::class);
    $miniApp->shouldReceive('validate')
        ->times($times)
        ->with('signed-test-payload')
        ->andReturn($telegramUser);

    app()->instance(TelegramMiniAppService::class, $miniApp);
}

function registrationDraftUser(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'telegram_id' => '800100200',
        'role' => 'cleaner',
        'status' => 'registration_draft',
        'is_active' => true,
        'password' => 'test-password',
    ], $attributes));
}

it('creates a restorable registration draft on first Telegram login without notifying administrators', function () {
    registrationTelegramLogin([
        'telegram_id' => '800100200',
        'username' => 'anna_test',
        'first_name' => 'Telegram',
        'last_name' => 'User',
        'source' => 'mini_app',
        'start_param' => 'control_result_123',
    ]);

    $notifications = Mockery::mock(TelegramUserNotificationService::class);
    $notifications->shouldNotReceive('accessRequested');
    $notifications->shouldNotReceive('accessPending');
    app()->instance(TelegramUserNotificationService::class, $notifications);

    $this->postJson(route('telegram.auth'), ['init_data' => 'signed-test-payload'])
        ->assertOk()
        ->assertJsonPath('redirect', route('access.registration'))
        ->assertJsonPath('user.status', 'registration_draft');

    $user = User::query()->sole();

    expect($user->telegram_id)->toBe('800100200')
        ->and($user->telegram_username)->toBe('anna_test')
        ->and($user->name)->toBeNull()
        ->and($user->birthday)->toBeNull()
        ->and($user->work_started_at)->toBeNull()
        ->and($user->registration_draft)->toBeArray()
        ->and(UserResource::getEloquentQuery()->whereKey($user->id)->exists())->toBeFalse();

    $this->actingAs($user)->get(route('page-home'))->assertRedirect(route('access.registration'));
});

it('restores the unfinished draft after a repeated Telegram login', function () {
    registrationTelegramLogin([
        'telegram_id' => '800100201',
        'username' => 'draft_user',
        'source' => 'mini_app',
    ], 2);

    $this->postJson(route('telegram.auth'), ['init_data' => 'signed-test-payload'])->assertOk();
    $user = User::query()->sole();

    Livewire::actingAs($user)->test('access.registration')
        ->set('firstName', 'Анна')
        ->set('lastName', 'Иванова')
        ->set('birthday', '1991-02-03')
        ->set('workStartedAt', '2021-07-08');

    $this->postJson(route('telegram.auth'), ['init_data' => 'signed-test-payload'])
        ->assertOk()
        ->assertJsonPath('redirect', route('access.registration'));

    $user->refresh();
    expect($user->registration_draft)->toMatchArray([
        'first_name' => 'Анна',
        'last_name' => 'Иванова',
        'birthday' => '1991-02-03',
        'work_started_at' => '2021-07-08',
    ]);

    Livewire::actingAs($user)->test('access.registration')
        ->assertSet('firstName', 'Анна')
        ->assertSet('lastName', 'Иванова')
        ->assertSet('birthday', '1991-02-03')
        ->assertSet('workStartedAt', '2021-07-08');
});

it('requires all registration fields and rejects future dates on the server', function () {
    $user = registrationDraftUser();
    $notifications = Mockery::mock(TelegramUserNotificationService::class);
    $notifications->shouldNotReceive('accessRequested');
    $notifications->shouldNotReceive('accessPending');
    app()->instance(TelegramUserNotificationService::class, $notifications);

    $form = Livewire::actingAs($user)->test('access.registration')
        ->call('submit')
        ->assertHasErrors(['firstName', 'lastName', 'birthday', 'workStartedAt']);

    $futureDate = now()->addDay()->toDateString();
    $form->set('firstName', 'Анна')
        ->set('lastName', 'Иванова')
        ->set('birthday', $futureDate)
        ->set('workStartedAt', $futureDate)
        ->call('submit')
        ->assertHasErrors(['birthday', 'workStartedAt']);

    expect($user->fresh()->status)->toBe('registration_draft')
        ->and($user->fresh()->name)->toBeNull();
});

it('submits once with the full name and employment dates and keeps the existing approval flow', function () {
    $user = registrationDraftUser();
    $notifications = Mockery::mock(TelegramUserNotificationService::class);
    $notifications->shouldReceive('accessRequested')->once()->with(Mockery::on(fn (User $notified): bool => $notified->is($user)));
    $notifications->shouldReceive('accessPending')->once()->with(Mockery::on(fn (User $notified): bool => $notified->is($user)));
    app()->instance(TelegramUserNotificationService::class, $notifications);

    $form = Livewire::actingAs($user)->test('access.registration')
        ->set('firstName', '  Анна ')
        ->set('lastName', ' Иванова  ')
        ->set('birthday', '1991-02-03')
        ->set('workStartedAt', '2021-07-08')
        ->call('submit')
        ->assertRedirect(route('access.pending'));

    $user->refresh();
    expect($user->name)->toBe('Анна Иванова')
        ->and($user->birthday?->toDateString())->toBe('1991-02-03')
        ->and($user->work_started_at?->toDateString())->toBe('2021-07-08')
        ->and($user->status)->toBe('pending')
        ->and($user->registration_draft)->toBeNull();

    $form->call('submit')->assertRedirect(route('access.pending'));
});

it('keeps automatic Telegram login for an already approved account', function () {
    $user = User::query()->create([
        'name' => 'Existing Employee',
        'telegram_id' => '800100202',
        'role' => 'supervisor',
        'status' => 'approved',
        'is_active' => true,
        'password' => 'existing-password',
    ]);

    registrationTelegramLogin([
        'telegram_id' => '800100202',
        'username' => 'existing_employee',
        'source' => 'mini_app',
    ]);

    $notifications = Mockery::mock(TelegramUserNotificationService::class);
    $notifications->shouldNotReceive('accessRequested');
    $notifications->shouldNotReceive('accessPending');
    app()->instance(TelegramUserNotificationService::class, $notifications);

    $this->postJson(route('telegram.auth'), ['init_data' => 'signed-test-payload'])
        ->assertOk()
        ->assertJsonPath('redirect', route('page-home'))
        ->assertJsonPath('user.id', $user->id);

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->name)->toBe('Existing Employee')
        ->and($user->fresh()->role)->toBe('supervisor')
        ->and($user->fresh()->status)->toBe('approved');
});

it('keeps an existing pending account in the existing review flow', function () {
    $user = User::query()->create([
        'name' => 'Existing Pending Employee',
        'telegram_id' => '800100203',
        'role' => 'cleaner',
        'status' => 'pending',
        'is_active' => true,
        'password' => 'existing-password',
    ]);

    registrationTelegramLogin([
        'telegram_id' => '800100203',
        'username' => 'pending_employee',
        'source' => 'mini_app',
    ]);

    $notifications = Mockery::mock(TelegramUserNotificationService::class);
    $notifications->shouldReceive('accessRequested')->once();
    $notifications->shouldReceive('accessPending')->once();
    app()->instance(TelegramUserNotificationService::class, $notifications);

    $this->postJson(route('telegram.auth'), ['init_data' => 'signed-test-payload'])
        ->assertOk()
        ->assertJsonPath('redirect', route('access.pending'))
        ->assertJsonPath('user.id', $user->id);

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->name)->toBe('Existing Pending Employee')
        ->and($user->fresh()->status)->toBe('pending');
});

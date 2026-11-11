<?php

use App\Filament\Resources\RewardPrograms\Pages\EditRewardProgram;
use App\Models\User;
use App\Services\UserApplicationBadgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.sqlite.url' => null,
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

    $this->user = User::query()->forceCreate([
        'name' => 'Test Administrator',
        'email' => 'admin@example.test',
        'password' => 'unused',
        'role' => 'admin',
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

it('does not render the retired TRIS Mare panel on the client profile', function () {
    Livewire::actingAs($this->user)
        ->test('page-profile')
        ->assertSee('Рабочие инструменты')
        ->assertDontSee('TRIS Mare 2026')
        ->assertDontSee('История начислений');
});

it('does not schedule TRIS Mare sync and keeps unrelated daily jobs', function () {
    config(['cache.default' => 'array']);

    expect(Artisan::call('schedule:list'))->toBe(0);
    $schedule = Artisan::output();

    expect($schedule)
        ->not->toContain('tris-mare:sync')
        ->toContain('calendar:notify-tomorrow', 'tasks:check-deadlines', 'mobility:sync');
});

it('does not expose the retired leaderboard from the reward program editor', function () {
    expect((new EditRewardProgram)->getHeaderWidgets())->toBe([]);
});

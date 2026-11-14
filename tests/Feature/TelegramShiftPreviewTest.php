<?php

use App\Filament\Resources\ShiftDuties\Pages\ManageShiftDuties;
use App\Filament\Resources\ShiftDuties\ShiftDutyResource;
use App\Models\ShiftDuty;
use App\Models\TelegramOperationalEvent;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOperationalEventObserver;
use App\Services\Telegram\TelegramShiftPreviewBuilder;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    Schema::table('users', function (Blueprint $table): void {
        $table->string('role')->default('supervisor');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
    });
    (require database_path('migrations/2026_11_15_000000_create_shift_duties_table.php'))->up();
    Carbon::setTestNow(Carbon::parse('2026-06-18 21:00:00', 'Europe/Rome'));
    config(['services.telegram.digest_districts' => [
        'alpha' => ['label' => 'Alpha', 'chat_id' => '-1001', 'latitude' => 45, 'longitude' => 9],
        'beta' => ['label' => 'Beta', 'chat_id' => '-1002', 'latitude' => 45, 'longitude' => 9],
    ]]);
    $this->mock(TelegramBotService::class)->shouldNotReceive('sendMessage');
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('previews the shift with one explicit duty covering multiple districts and isolated ledger outcomes', function () {
    $user = User::query()->create(['name' => 'Synthetic Supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    foreach (['alpha', 'beta'] as $key) {
        ShiftDuty::create(['date' => '2026-06-17', 'district_key' => $key, 'user_id' => $user->id]);
    }
    $observer = app(TelegramOperationalEventObserver::class);
    $problem = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '100');
    $observer->observe($problem);
    $resolution = TelegramOperationalTestDatabase::message('Замок в квартире исправлен', '2026-06-17 08:20:00', '101',
        ['message' => ['reply_to_message' => ['message_id' => 100]]]);
    expect($observer->observe($resolution)['outcome'])->toBe('resolved');
    $open = TelegramOperationalTestDatabase::message('Не работает свет в ванной.', '2026-06-17 09:00:00', '200', chatId: '-1002');
    $observer->observe($open);
    $eventCount = TelegramOperationalEvent::count();

    expect(Artisan::call('telegram:shift-preview', ['--date' => '2026-06-17', '--json' => true]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['districts'])->toHaveCount(2)
        ->and(array_column($preview['districts'], 'duty_status'))->toBe(['assigned', 'assigned'])
        ->and(array_column(array_column($preview['districts'], 'duty'), 'user_id'))->toBe([$user->id, $user->id])
        ->and($preview['districts'][0]['report']['events'][0]['status'])->toBe('resolved')
        ->and($preview['districts'][1]['report']['events'][0]['status'])->toBe('open')
        ->and($preview['mode'])->toBe(['read_only' => true, 'telegram_actions' => 0, 'mutations' => 0])
        ->and(TelegramOperationalEvent::count())->toBe($eventCount)
        ->and(ShiftDuty::count())->toBe(2);
});

it('keeps absent and inactive duties explicit without borrowing another date', function () {
    $user = User::create(['name' => 'Inactive', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => false]);
    ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $user->id]);
    ShiftDuty::create(['date' => '2026-06-16', 'district_key' => 'beta', 'user_id' => $user->id]);
    $preview = app(TelegramShiftPreviewBuilder::class)->build(Carbon::parse('2026-06-17', 'Europe/Rome'));
    expect(array_column($preview['districts'], 'duty_status'))->toBe(['unavailable', 'unassigned'])
        ->and(array_column($preview['districts'], 'duty'))->toBe([null, null]);
});

it('rejects duplicate district duties while allowing the same employee on another district', function () {
    $user = User::create(['name' => 'Synthetic']);
    ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $user->id]);
    expect(fn () => ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

it('restricts assignment management to admins for every write action', function () {
    $supervisor = User::create(['name' => 'Synthetic Supervisor', 'role' => 'supervisor']);
    $duty = new ShiftDuty;
    $this->actingAs($supervisor);
    expect(ShiftDutyResource::canViewAny())->toBeFalse()
        ->and(ShiftDutyResource::canCreate())->toBeFalse()
        ->and(ShiftDutyResource::canEdit($duty))->toBeFalse()
        ->and(ShiftDutyResource::canDelete($duty))->toBeFalse();
    $supervisor->role = 'admin';
    expect(ShiftDutyResource::canViewAny())->toBeTrue()->and(ShiftDutyResource::canCreate())->toBeTrue();
});

it('rejects invalid preview dates', function (string $date) {
    expect(Artisan::call('telegram:shift-preview', ['--date' => $date]))->toBe(1);
})->with(['', '2026-02-30', '2026-6-1']);

it('creates and validates explicit duties through the admin action', function () {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($admin)->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageShiftDuties::class)
        ->callAction('create', data: ['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $admin->id])
        ->assertHasNoActionErrors();
    expect(ShiftDuty::count())->toBe(1);
    Livewire::test(ManageShiftDuties::class)
        ->callAction('create', data: ['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $admin->id])
        ->assertHasActionErrors(['district_key' => 'unique']);
});

it('rejects forged unknown districts and inactive duty employees in the admin form', function () {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $inactive = User::create(['name' => 'Inactive', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => false]);
    $this->actingAs($admin)->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageShiftDuties::class)
        ->callAction('create', data: ['date' => '2026-06-17', 'district_key' => 'unknown', 'user_id' => $inactive->id])
        ->assertHasActionErrors(['district_key', 'user_id']);
    expect(ShiftDuty::count())->toBe(0);
});

<?php

use App\Filament\Resources\ShiftDuties\Pages\ManageShiftDuties;
use App\Filament\Resources\ShiftDuties\ShiftDutyResource;
use App\Filament\Resources\ShiftSituations\Pages\ManageShiftSituations;
use App\Filament\Resources\ShiftSituations\ShiftSituationResource;
use App\Models\ShiftDuty;
use App\Models\ShiftSituation;
use App\Models\TelegramOperationalEvent;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOperationalEventObserver;
use App\Services\Telegram\TelegramShiftPreviewBuilder;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
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
    (require database_path('migrations/2026_11_15_000001_create_shift_situations_table.php'))->up();
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

it('persists an explicit situation assignee and manual decision through admin actions and previews them', function () {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $duty = User::create(['name' => 'Synthetic Duty', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $assignee = User::create(['name' => 'Synthetic Executor', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $duty->id]);
    ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'beta', 'user_id' => $duty->id]);
    $message = TelegramOperationalTestDatabase::message('Не работает свет в ванной.', '2026-06-17 09:00:00', '301');
    app(TelegramOperationalEventObserver::class)->observe($message);
    $event = TelegramOperationalEvent::sole();
    $this->actingAs($admin)->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = Livewire::test(ManageShiftSituations::class);
    $page->callAction('create', data: [
        'date' => '2026-06-17', 'district_key' => 'alpha', 'event_key' => $event->event_key,
        'user_id' => $assignee->id, 'decision' => null, 'recorded_by' => $duty->id,
    ])->assertHasNoActionErrors();
    $situation = ShiftSituation::sole();
    expect($situation->recorded_by)->toBe($admin->id)->and($situation->decision)->toBeNull();
    $page->callAction(TestAction::make('edit')->table($situation), data: [
        'decision' => 'Вызвать электрика и заменить лампу до заселения.',
    ])->assertHasNoActionErrors();
    expect($situation->fresh()->decision)->toBe('Вызвать электрика и заменить лампу до заселения.');
    expect(Artisan::call('telegram:shift-preview', ['--date' => '2026-06-17', '--json' => true]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['districts'][0]['duty']['user_id'])->toBe($duty->id)
        ->and($preview['districts'][1]['duty']['user_id'])->toBe($duty->id)
        ->and($preview['districts'][0]['situations'][0]['assignee']['user_id'])->toBe($assignee->id)
        ->and($preview['districts'][0]['situations'][0]['decision'])->toBe('Вызвать электрика и заменить лампу до заселения.')
        ->and($preview['districts'][0]['situations'][0]['ledger_status'])->toBe('open')
        ->and($preview['districts'][1]['situations'])->toBe([])
        ->and($event->fresh()->status)->toBe('open');
    expect(Artisan::call('telegram:shift-preview', ['--date' => '2026-06-17']))->toBe(0)
        ->and(Artisan::output())->toContain('Synthetic Executor', 'Вызвать электрика и заменить лампу до заселения.');
    $resolution = TelegramOperationalTestDatabase::message('Починили, всё готово', '2026-06-17 11:00:00', '302',
        ['message' => ['reply_to_message' => ['message_id' => 301]]]);
    expect(app(TelegramOperationalEventObserver::class)->observe($resolution)['outcome'])->toBe('resolved');
    $completed = app(TelegramShiftPreviewBuilder::class)->build(Carbon::parse('2026-06-17', 'Europe/Rome'));
    expect($completed['districts'][0]['situations'][0]['ledger_status'])->toBe('resolved')
        ->and($completed['districts'][0]['situations'][0]['assignee']['user_id'])->toBe($assignee->id)
        ->and($completed['districts'][0]['situations'][0]['decision'])->toBe('Вызвать электрика и заменить лампу до заселения.');
});

it('rejects event keys from another district or date and absent ledger keys', function (string $date, string $district, bool $missing) {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $message = TelegramOperationalTestDatabase::message('Не работает свет в ванной.', '2026-06-17 09:00:00', '401');
    app(TelegramOperationalEventObserver::class)->observe($message);
    $this->actingAs($admin)->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageShiftSituations::class)
        ->callAction('create', data: [
            'date' => $date, 'district_key' => $district,
            'event_key' => $missing ? 'missing-event' : TelegramOperationalEvent::sole()->event_key,
            'user_id' => $admin->id, 'decision' => 'Synthetic decision',
        ])->assertHasActionErrors(['event_key']);
    expect(ShiftSituation::count())->toBe(0);
})->with([
    ['2026-06-17', 'beta', false],
    ['2026-06-16', 'alpha', false],
    ['2026-06-17', 'alpha', true],
]);

it('does not assign a case implicitly and retains a manual decision with explicit unavailable source status', function () {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    ShiftDuty::create(['date' => '2026-06-17', 'district_key' => 'alpha', 'user_id' => $admin->id]);
    $message = TelegramOperationalTestDatabase::message('Не работает свет в ванной.', '2026-06-17 09:00:00', '501');
    app(TelegramOperationalEventObserver::class)->observe($message);
    $event = TelegramOperationalEvent::sole();
    $date = Carbon::parse('2026-06-17', 'Europe/Rome');
    $before = app(TelegramShiftPreviewBuilder::class)->build($date);
    expect($before['districts'][0]['situations'][0]['assignment_status'])->toBe('unassigned')
        ->and($before['districts'][0]['situations'][0]['assignee'])->toBeNull();
    ShiftSituation::create([
        'date' => '2026-06-17', 'district_key' => 'alpha', 'event_key' => $event->event_key,
        'user_id' => $admin->id, 'decision' => 'Решение сохранено.', 'recorded_by' => $admin->id,
    ]);
    $event->evidence()->update(['is_current_revision' => false]);
    $after = app(TelegramShiftPreviewBuilder::class)->build($date);
    expect($after['districts'][0]['situations'][0]['source_status'])->toBe('unavailable')
        ->and($after['districts'][0]['situations'][0]['decision'])->toBe('Решение сохранено.');
});

it('guards situation writes and rejects inactive assignees and repeated event decisions', function () {
    $admin = User::create(['name' => 'Synthetic Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $inactive = User::create(['name' => 'Inactive', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => false]);
    $this->actingAs($inactive);
    expect(ShiftSituationResource::canViewAny())->toBeFalse()
        ->and(ShiftSituationResource::canCreate())->toBeFalse()
        ->and(ShiftSituationResource::canEdit(new ShiftSituation))->toBeFalse()
        ->and(ShiftSituationResource::canDelete(new ShiftSituation))->toBeFalse();
    $this->actingAs($admin)->withoutVite();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $message = TelegramOperationalTestDatabase::message('Не работает свет в ванной.', '2026-06-17 09:00:00', '601');
    app(TelegramOperationalEventObserver::class)->observe($message);
    $data = ['date' => '2026-06-17', 'district_key' => 'alpha', 'event_key' => TelegramOperationalEvent::sole()->event_key,
        'user_id' => $inactive->id, 'decision' => 'Проверить свет.'];
    Livewire::test(ManageShiftSituations::class)
        ->callAction('create', data: $data)->assertHasActionErrors(['user_id']);
    $data['user_id'] = $admin->id;
    Livewire::test(ManageShiftSituations::class)
        ->callAction('create', data: $data)->assertHasNoActionErrors();
    Livewire::test(ManageShiftSituations::class)
        ->callAction('create', data: $data)->assertHasActionErrors(['event_key' => 'unique']);
    expect(ShiftSituation::count())->toBe(1);
});

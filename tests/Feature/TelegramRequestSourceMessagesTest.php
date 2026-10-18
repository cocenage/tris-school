<?php

use App\Models\InventoryRequest;
use App\Models\InventoryRequestLine;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use App\Services\Forms\InventoryRequestTelegramService;
use App\Services\Forms\VacationRequestTelegramService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'services.telegram.bot_token' => 'work-test-token',
        'services.telegram.chat_id_vacation' => '-1001',
        'services.staff_forms.chat_id' => '-1001', 'services.staff_forms.inventory_thread_id' => '15',
    ]);
    DB::purge('sqlite');
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2026_04_14_181633_create_vacation_requests_table.php',
        '2026_04_14_181644_create_vacation_request_days_table.php',
        '2026_04_17_172701_create_inventory_items_table.php',
        '2026_04_17_172707_create_inventory_requests_table.php',
        '2026_04_17_172713_create_inventory_request_lines_table.php',
        '2026_10_18_000001_add_request_telegram_source_messages.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]], 200)]);
});

afterEach(function () {
    DB::purge('sqlite');
});

it('keeps each vacation date decision and synchronizes the original Telegram message', function () {
    $employee = User::create(['name' => 'Employee', 'role' => 'cleaner']);
    $manager = User::create(['name' => 'Manager', 'role' => 'supervisor', 'status' => 'approved']);
    $request = VacationRequest::create([
        'user_id' => $employee->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-21',
        'days_count' => 2, 'reason' => 'Отпуск', 'status' => 'pending',
    ]);
    $first = VacationRequestDay::create(['vacation_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-20']);
    VacationRequestDay::create(['vacation_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-21']);
    app(VacationRequestTelegramService::class)->sendCreated($request);

    $first->update(['status' => 'approved', 'reviewed_by' => $manager->id, 'reviewed_at' => now()]);
    $request->syncStatusAndNotify();

    expect($request->fresh()->telegram_source_message_id)->toBe(700)
        ->and($first->fresh()->reviewed_by)->toBe($manager->id)
        ->and($first->fresh()->reviewed_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
        && str_contains((string) $request['text'], 'частично рассмотрена')
        && str_contains((string) $request['text'], 'Manager')
        && str_contains((string) $request['text'], 'ожидает'));
});

it('updates an inventory source message after a line decision and preserves quantities', function () {
    $employee = User::create(['name' => 'Employee', 'role' => 'cleaner']);
    $manager = User::create(['name' => 'Manager', 'role' => 'supervisor', 'status' => 'approved']);
    $itemId = DB::table('inventory_items')->insertGetId(['main' => '{}', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $request = InventoryRequest::create(['user_id' => $employee->id, 'status' => 'pending']);
    $line = InventoryRequestLine::create([
        'inventory_request_id' => $request->id, 'inventory_item_id' => $itemId,
        'user_id' => $employee->id, 'item_name' => 'Полотенца', 'requested_qty' => 5,
        'issued_qty' => 0, 'status' => 'pending',
    ]);
    app(InventoryRequestTelegramService::class)->sendNewRequest($request);

    $line->update(['status' => 'partially_issued', 'issued_qty' => 3, 'processed_by' => $manager->id, 'processed_at' => now()]);
    $request->syncStatusAndNotify();

    expect($request->fresh()->telegram_source_message_id)->toBe(700)
        ->and($line->fresh()->issued_qty)->toBe(3);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText')
        && str_contains((string) $request['text'], 'Полотенца')
        && str_contains((string) $request['text'], '3/5')
        && str_contains((string) $request['text'], 'Manager'));
});

it('keeps a vacation decision when editing the Telegram source fails', function () {
    $employee = User::create(['name' => 'Employee']);
    $manager = User::create(['name' => 'Manager', 'role' => 'supervisor']);
    $request = VacationRequest::create([
        'user_id' => $employee->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20',
        'days_count' => 1, 'reason' => 'Отпуск', 'status' => 'pending',
    ]);
    $day = VacationRequestDay::create(['vacation_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-20']);
    app(VacationRequestTelegramService::class)->sendCreated($request);
    Http::swap(new HttpFactory);
    Http::fake(fn ($request) => Http::response(['ok' => false], str_ends_with($request->url(), '/editMessageText') ? 500 : 200));

    $day->update(['status' => 'rejected', 'reviewed_by' => $manager->id, 'reviewed_at' => now()]);
    $request->syncStatusAndNotify();

    expect($day->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->telegram_source_message_id)->toBe(700);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText'));
});

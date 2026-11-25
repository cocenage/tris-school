<?php

use App\Jobs\DeliverStructuredRequestDecision;
use App\Jobs\SendRequestResultNotificationJob;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\InventoryRequest;
use App\Models\InventoryRequestLine;
use App\Models\StructuredRequestDecision;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use App\Services\Forms\DayOffRequestTelegramService;
use App\Services\Forms\InventoryRequestTelegramService;
use App\Services\Forms\StructuredRequestReview;
use App\Services\Forms\TelegramStructuredRequestService;
use App\Services\Forms\VacationRequestTelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'services.telegram.bot_token' => 'test-structured-token',
        'services.telegram.bot_username' => 'TrisAcademyBot',
        'services.telegram.rich_messages_enabled' => false,
        'services.telegram.chat_id_formweekend' => '-100123456',
        'services.telegram.thread_id_formweekend' => '20',
        'services.telegram.chat_id_vacation' => '-100123456',
        'services.telegram.thread_id_vacation' => '21',
        'services.staff_forms.chat_id' => '-100123456',
        'services.staff_forms.inventory_thread_id' => '22',
        'services.staff_forms.day_off_telegram_review_enabled' => true,
        'services.staff_forms.vacation_telegram_review_enabled' => true,
        'services.staff_forms.inventory_telegram_review_enabled' => true,
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
    DB::purge('sqlite');
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2026_04_08_115509_create_day_off_requests_table.php',
        '2026_04_08_115519_create_day_off_request_days_table.php',
        '2026_04_14_181633_create_vacation_requests_table.php',
        '2026_04_14_181644_create_vacation_request_days_table.php',
        '2026_04_17_172701_create_inventory_items_table.php',
        '2026_04_17_172707_create_inventory_requests_table.php',
        '2026_04_17_172713_create_inventory_request_lines_table.php',
        '2026_10_18_000001_add_request_telegram_source_messages.php',
        '2026_10_21_000000_create_telegram_outbound_operations_table.php',
        '2026_11_25_110000_add_structured_request_reviews.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    foreach (['day_off_requests', 'vacation_requests', 'inventory_requests'] as $name) {
        Schema::table($name, function (Blueprint $table): void {
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('answer_seen_at')->nullable();
        });
    }
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 700]], 200));
    $this->employee = User::create(['name' => 'Анна', 'telegram_id' => '12345', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $this->manager = User::create(['name' => 'Supervisor', 'telegram_id' => '23456', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $this->admin = User::create(['name' => 'Admin', 'telegram_id' => '34567', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $this->review = app(StructuredRequestReview::class);
    $this->telegram = app(TelegramStructuredRequestService::class);
});

afterEach(function (): void {
    DB::purge('sqlite');
});

function structuredReviewInitData(User $actor, string $start): string
{
    $data = [
        'auth_date' => (string) now()->timestamp,
        'start_param' => $start,
        'user' => json_encode(['id' => (int) $actor->telegram_id, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
    ];
    ksort($data);
    $check = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
    $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', config('services.telegram.bot_token'), 'WebAppData', true));
    return http_build_query($data);
}

function createStructuredAbsence(User $user, string $kind): array
{
    if ($kind === 'day_off') {
        $record = DayOffRequest::create(['user_id' => $user->id, 'reason' => 'Семейные обстоятельства', 'status' => 'pending']);
        $first = DayOffRequestDay::create(['day_off_request_id' => $record->id, 'user_id' => $user->id, 'date' => '2026-12-01']);
        $second = DayOffRequestDay::create(['day_off_request_id' => $record->id, 'user_id' => $user->id, 'date' => '2026-12-02']);
        app(DayOffRequestTelegramService::class)->sendCreated($record);
    } else {
        $record = VacationRequest::create(['user_id' => $user->id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'days_count' => 2, 'reason' => 'Поездка', 'status' => 'pending']);
        $first = VacationRequestDay::create(['vacation_request_id' => $record->id, 'user_id' => $user->id, 'date' => '2026-12-01']);
        $second = VacationRequestDay::create(['vacation_request_id' => $record->id, 'user_id' => $user->id, 'date' => '2026-12-02']);
        app(VacationRequestTelegramService::class)->sendCreated($record);
    }
    return [$record->fresh(), $first, $second];
}

function createStructuredInventory(User $user): array
{
    $itemId = DB::table('inventory_items')->insertGetId(['main' => '{}', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $record = InventoryRequest::create(['user_id' => $user->id, 'status' => 'pending']);
    $first = InventoryRequestLine::create(['inventory_request_id' => $record->id, 'inventory_item_id' => $itemId, 'user_id' => $user->id, 'item_name' => 'Перчатки', 'requested_qty' => 5]);
    $second = InventoryRequestLine::create(['inventory_request_id' => $record->id, 'inventory_item_id' => $itemId, 'user_id' => $user->id, 'item_name' => 'Микрофибра', 'requested_qty' => 3]);
    app(InventoryRequestTelegramService::class)->sendNewRequest($record);
    return [$record->fresh(), $first, $second];
}

it('reviews all requested dates atomically, includes reasons and sends one final response', function (string $kind): void {
    [$record, $first, $second] = createStructuredAbsence($this->employee, $kind);
    expect((int) $record->telegram_review_version)->toBe(0);
    Http::assertSent(fn ($r) => str_contains($r['text'], '01.12.2026') && str_contains($r['text'], '02.12.2026')
        && str_contains($r['reply_markup']['inline_keyboard'][0][0]['url'], 'startapp=review_'.$kind.'_'.$record->id.'&mode=compact'));
    $start = 'review_'.$kind.'_'.$record->id;
    $this->postJson(route('telegram.auth'), ['init_data' => structuredReviewInitData($this->employee, $start)])->assertForbidden();
    $this->postJson(route('telegram.auth'), ['init_data' => structuredReviewInitData($this->manager, $start)])->assertOk();
    $url = route('structured.decision.show', [$kind, $record->id]);
    $this->get($url)->assertOk()->assertSee('01.12.2026')->assertSee('02.12.2026')->assertSee('Одобрить всё');
    $fingerprint = $this->review->fingerprint($kind, $record);
    $items = [$first->id => ['choice' => 'approved'], $second->id => ['choice' => 'rejected', 'reason' => ' ']];
    $this->postJson($url, ['fingerprint' => $fingerprint, 'items' => $items])->assertUnprocessable();
    expect($first->fresh()->status)->toBe('pending')->and($second->fresh()->status)->toBe('pending');
    $items[$second->id]['reason'] = 'Недостаточно сотрудников.';
    $this->post($url, ['fingerprint' => $fingerprint, 'items' => $items])->assertOk()->assertSee('Решение сохранено');
    expect($record->fresh()->status)->toBe('partially_approved')->and($first->fresh()->status)->toBe('approved')
        ->and($second->fresh()->status)->toBe('rejected')->and($second->fresh()->admin_comment)->toBe('Недостаточно сотрудников.');
    expect($record->fresh()->approvedDays()->count())->toBe(1);
    $decision = StructuredRequestDecision::query()->sole();
    Queue::assertPushed(DeliverStructuredRequestDecision::class);
    Queue::assertNotPushed(SendRequestResultNotificationJob::class);
    $job = new DeliverStructuredRequestDecision($decision->id);
    $job->handle($this->telegram);
    $job->handle($this->telegram);
    expect($decision->fresh()->delivery_status)->toBe('sent')->and($decision->fresh()->card_sync_status)->toBe('synced');
    expect($record->fresh()->notified_at)->not->toBeNull();
    expect(Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '12345')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Недостаточно сотрудников.'));
    $this->postJson($url, ['fingerprint' => $fingerprint, 'items' => $items])->assertUnprocessable();
    expect(StructuredRequestDecision::query()->count())->toBe(1);
})->with(['day_off', 'vacation']);

it('requires all pending dates and rejects a stale snapshot without modifying previous decisions', function (): void {
    [$record, $first, $second] = createStructuredAbsence($this->employee, 'vacation');
    $old = $this->review->fingerprint('vacation', $record);
    expect(fn () => $this->review->decide('vacation', $record, $this->manager, $old, [$first->id => ['choice' => 'approved']], ''))->toThrow(ValidationException::class);
    $first->update(['status' => 'approved', 'reviewed_by' => $this->admin->id, 'reviewed_at' => now()]);
    $items = [$first->id => ['choice' => 'rejected', 'reason' => 'Нельзя'], $second->id => ['choice' => 'approved']];
    expect(fn () => $this->review->decide('vacation', $record, $this->manager, $old, $items, ''))->toThrow(ValidationException::class);
    $record->refresh();
    $fresh = $this->review->fingerprint('vacation', $record);
    $this->review->decide('vacation', $record, $this->manager, $fresh, [$second->id => ['choice' => 'approved']], '');
    expect($first->fresh()->reviewed_by)->toBe($this->admin->id)->and($record->fresh()->status)->toBe('approved');
});

it('uses approved quantities, not issued quantities, and links the exact editable office invitation', function (): void {
    [$record, $first, $second] = createStructuredInventory($this->employee);
    $start = 'review_inventory_'.$record->id;
    $this->postJson(route('telegram.auth'), ['init_data' => structuredReviewInitData($this->manager, $start)])->assertOk();
    $url = route('structured.decision.show', ['inventory', $record->id]);
    $this->get($url)->assertOk()->assertSee('Ждём тебя в нашем офисе для получения рабочего инвентаря.')->assertSee('Via Luigi Calamatta 14, Milano');
    $fingerprint = $this->review->fingerprint('inventory', $record);
    $items = [$first->id => ['qty' => 6], $second->id => ['qty' => 0, 'reason' => 'Нет в наличии']];
    $this->postJson($url, ['fingerprint' => $fingerprint, 'items' => $items, 'comment' => StructuredRequestReview::PICKUP_MESSAGE])->assertUnprocessable();
    $items[$first->id] = ['qty' => 3];
    $this->postJson($url, ['fingerprint' => $fingerprint, 'items' => $items, 'comment' => StructuredRequestReview::PICKUP_MESSAGE])->assertUnprocessable();
    $items[$first->id]['reason'] = 'Есть три пары';
    $this->postJson($url, ['fingerprint' => $fingerprint, 'items' => $items, 'comment' => ' '])->assertUnprocessable();
    $this->post($url, ['fingerprint' => $fingerprint, 'items' => $items, 'comment' => StructuredRequestReview::PICKUP_MESSAGE])->assertOk();
    expect($record->fresh()->status)->toBe('partially_approved')->and((int) $first->fresh()->approved_qty)->toBe(3)
        ->and((int) $first->fresh()->issued_qty)->toBe(0)->and($second->fresh()->status)->toBe('rejected');
    $decision = StructuredRequestDecision::query()->sole();
    expect($decision->snapshot['comment'])->toBe(StructuredRequestReview::PICKUP_MESSAGE);
    (new DeliverStructuredRequestDecision($decision->id))->handle($this->telegram);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '12345'
        && str_contains($r['text'], '<a href="'.e(StructuredRequestReview::PICKUP_URL).'">Via Luigi Calamatta 14, Milano</a>')
        && str_contains($r['text'], 'Есть три пары') && str_contains($r['text'], '3 из 5'));
});

it('does not include an office invitation in a fully rejected inventory request', function (): void {
    [$record, $first, $second] = createStructuredInventory($this->employee);
    $decision = $this->review->decide('inventory', $record, $this->manager, $this->review->fingerprint('inventory', $record), [
        $first->id => ['qty' => 0, 'reason' => 'Нет'], $second->id => ['qty' => 0, 'reason' => 'Нет'],
    ], StructuredRequestReview::PICKUP_MESSAGE);
    expect($record->fresh()->status)->toBe('rejected')->and($record->fresh()->admin_comment)->toBeNull()
        ->and($decision->result_html)->not->toContain('Via Luigi Calamatta');
});

it('lets only an administrator open the backup review in Academy and refuses unsigned Telegram access', function (): void {
    [$record] = createStructuredAbsence($this->employee, 'day_off');
    $params = ['day_off', $record->id];
    $this->actingAs($this->manager)->get(route('structured.decision.show', $params))->assertForbidden();
    $this->get(route('structured.admin.show', $params))->assertForbidden();
    $this->actingAs($this->admin)->get(route('structured.admin.show', $params))->assertOk();
});

it('keeps historical inventory requests and cards on the old workflow while the flag is disabled', function (): void {
    config(['services.staff_forms.inventory_telegram_review_enabled' => false]);
    [$record, $first] = createStructuredInventory($this->employee);
    expect($record->telegram_review_version)->toBeNull()->and($first->fresh()->approved_qty)->toBeNull();
    $first->update(['issued_qty' => 5, 'status' => 'issued']);
    $record->recalculateStatus();
    expect((int) $first->fresh()->issued_qty)->toBe(5)->and(StructuredRequestDecision::query()->count())->toBe(0);
});

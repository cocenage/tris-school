<?php

use App\Http\Controllers\TelegramWorkWebhookController;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramUpdateIngestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.main_bot_auto_replies_enabled' => true,
        'services.telegram.work_webhook_secret' => 'test-secret',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.rich_messages_enabled' => true,
    ]);

    Cache::flush();

    Schema::dropIfExists('day_off_request_days');
    Schema::dropIfExists('day_off_requests');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('telegram_id')->nullable();
        $table->string('telegram_username')->nullable();
        $table->string('status')->default('pending');
        $table->string('role')->default('cleaner');
        $table->boolean('is_active')->default(true);
        $table->timestamp('approved_at')->nullable();
        $table->unsignedBigInteger('approved_by')->nullable();
        $table->timestamp('notified_at')->nullable();
        $table->timestamp('telegram_access_approved_notified_at')->nullable();
        $table->timestamp('telegram_access_requested_notified_at')->nullable();
        $table->timestamp('telegram_write_access_granted_at')->nullable();
        $table->timestamp('telegram_last_auth_at')->nullable();
        $table->string('telegram_login_source')->nullable();
        $table->timestamps();
    });

    Schema::create('day_off_requests', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->text('reason')->nullable();
        $table->string('status')->default('pending');
        $table->text('admin_comment')->nullable();
        $table->timestamp('submitted_at')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->timestamp('notified_at')->nullable();
        $table->timestamps();
    });

    Schema::create('day_off_request_days', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('day_off_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status')->default('pending');
        $table->text('admin_comment')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->timestamps();
    });

    foreach ([
        '2026_09_27_000000_create_telegram_scheduled_messages_tables.php',
        '2026_10_01_020000_create_scheduled_control_response_tables.php',
        '2026_10_03_000000_allow_replyless_scheduled_control_responses.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
});

afterEach(function () {
    TelegramOperationalTestDatabase::purge();
    Schema::dropIfExists('telegram_scheduled_control_summary_deliveries');
    Schema::dropIfExists('telegram_scheduled_message_responses');
    Schema::dropIfExists('telegram_scheduled_message_deliveries');
    Schema::dropIfExists('telegram_scheduled_messages');
    Schema::dropIfExists('day_off_request_days');
    Schema::dropIfExists('day_off_requests');
    Schema::dropIfExists('users');
});

function privateWebhookPayload(int $messageId = 42): array
{
    return [
        'update_id' => 1000 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'date' => now()->timestamp,
            'chat' => ['id' => 777, 'type' => 'private'],
            'from' => ['id' => 777, 'first_name' => 'Test'],
            'text' => 'Я заболела',
        ],
    ];
}

function workPollingPayload(int $messageId, int $updateId, string $chatId = '-100'): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $messageId,
            'date' => now()->timestamp,
            'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Work chat'],
            'from' => ['id' => 777, 'first_name' => 'Worker', 'is_bot' => false],
            'text' => 'Не работает замок',
        ],
    ];
}

function fakeWorkPollResponses(): ResponseSequence
{
    $sequence = Http::sequence();
    Http::fake(['api.telegram.org/*' => $sequence]);

    return $sequence;
}

it('dispatches operational observation only after a persisted work message when enabled', function () {
    config(['services.telegram.operational_observer_enabled' => true]);
    Queue::fake();
    Http::fake();

    $savedMessage = new TelegramMessage;
    $savedMessage->setConnection('analytics');
    $savedMessage->setRawAttributes(['id' => 501], true);
    $savedMessage->exists = true;

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->once()->andReturn($savedMessage);
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnFalse();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'update_id' => 501,
        'message' => [
            'message_id' => 501,
            'date' => now()->timestamp,
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'is_bot' => false],
            'text' => 'Проблема с замком',
        ],
    ])->assertOk();

    Queue::assertPushed(ProcessTelegramOperationalMessage::class, fn ($job) => $job->telegramMessageId === 501 && $job->mode === 'message'
    );
    expect(Http::recorded())->toHaveCount(0);
});

it('ingests configured district sources through the work webhook without running the assistant', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.operational_observer_enabled' => true,
        'services.telegram.digest_districts' => [
            'navigli' => [
                'label' => 'Navigli', 'chat_id' => '-200', 'duty_thread_id' => '285365',
                'latitude' => 45.4514, 'longitude' => 9.1749,
            ],
        ],
    ]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->never();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 503,
            'date' => now()->timestamp,
            'chat' => ['id' => -200, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'is_bot' => false],
            'text' => 'Не работает замок',
        ],
    ])->assertOk();

    $savedMessage = TelegramMessage::query()->where('message_id', '503')->sole();
    expect($savedMessage->chat->telegram_chat_id)->toBe('-200');
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 1);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, fn ($job) => $job->telegramMessageId === $savedMessage->id && $job->mode === 'message'
    );
});

it('does not ingest an unsupported group when the work allowlist is empty', function () {
    config([
        'services.telegram.work_allowed_chat_ids' => [],
        'services.telegram.operational_chat_ids' => [],
        'services.telegram.digest_districts' => [],
    ]);
    Queue::fake();
    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldNotReceive('ingest');
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 504,
            'chat' => ['id' => -300, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'is_bot' => false],
            'text' => 'Не работает замок',
        ],
    ])->assertOk()->assertJson(['skipped' => 'chat_not_allowed']);

    Queue::assertNothingPushed();
});

it('keeps work-message observation disabled by default', function () {
    config(['services.telegram.operational_observer_enabled' => false]);
    Queue::fake();

    $savedMessage = new TelegramMessage;
    $savedMessage->setRawAttributes(['id' => 502], true);
    $savedMessage->exists = true;

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->once()->andReturn($savedMessage);
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnFalse();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 502,
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'text' => 'Обычное сообщение',
        ],
    ])->assertOk();

    Queue::assertNothingPushed();
});

it('suppresses the private automatic fallback when replies are disabled', function () {
    config(['services.telegram.main_bot_auto_replies_enabled' => false]);
    Http::fake();

    $this->postJson('/telegram/work-webhook/test-secret', privateWebhookPayload())->assertOk();

    Http::assertNothingSent();
});

it('answers private messages without ingesting or invoking the assistant', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 901]]));

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->never();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $response = $this->postJson('/telegram/work-webhook/test-secret', privateWebhookPayload());

    $response->assertOk()->assertJson([
        'ok' => true,
        'skipped' => 'private_message',
    ]);

    Http::assertSent(function (ClientRequest $request): bool {
        return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === 777
            && $request['text'] === 'Я не обрабатываю личные сообщения. Используйте рабочий чат.';
    });
});

it('does not send a duplicate fallback for a repeated private webhook update', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 902]]));

    $payload = privateWebhookPayload(43);
    $first = $this->postJson('/telegram/work-webhook/test-secret', $payload);
    $second = $this->postJson('/telegram/work-webhook/test-secret', $payload);

    $first->assertOk();
    $second->assertOk();
    expect(Http::recorded())->toHaveCount(1);
});

it('authorizes and applies an access approval callback from a mapped supervisor', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 903]]));

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $response = $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-1',
            'data' => 'access:approve:'.$employee->id,
            'from' => ['id' => (int) $reviewer->telegram_id, 'first_name' => 'Supervisor'],
            'message' => ['message_id' => 10, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($employee->refresh()->status)->toBe('approved');
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
        && $request['callback_query_id'] === 'callback-1'
    );
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageText')
        && ($request['reply_markup']['inline_keyboard'] ?? null) === []
    );
    Http::assertNotSent(fn (ClientRequest $request): bool => ($request['message_id'] ?? null) === 10
        && array_key_exists('rich_message', $request->data())
    );
});

it('rejects a callback from a Telegram user without an approved mapping', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-2',
            'data' => 'access:approve:'.$employee->id,
            'from' => ['id' => 999],
            'message' => ['message_id' => 11, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['skipped' => 'reviewer_not_allowed']);
    expect($employee->refresh()->status)->toBe('pending');
});

it('keeps an access decision when Telegram delivery fails after the database write', function () {
    Http::fake(fn () => throw new RuntimeException('simulated Telegram timeout'));

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $response = $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-delivery-failure',
            'data' => 'access:approve:'.$employee->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => ['message_id' => 19, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($employee->refresh()->status)->toBe('approved');
});

it('applies an access rejection callback for an approved moderator', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $reviewer = User::create([
        'name' => 'Admin', 'telegram_id' => '321', 'status' => 'approved', 'role' => 'admin', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '654', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-reject',
            'data' => 'access:reject:'.$employee->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => ['message_id' => 20, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk();

    expect($employee->refresh()->status)->toBe('rejected');
});

it('accepts callback updates through the web webhook alias', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-web',
            'data' => 'unknown:action:1',
            'from' => ['id' => 999],
            'message' => ['message_id' => 11, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'skipped' => 'unknown_callback']);
});

it('routes callbacks independently of the work message allowlist', function () {
    config(['services.telegram.work_allowed_chat_ids' => []]);
    Http::fake(fn () => Http::response(['ok' => true]));

    $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-outside-work',
            'data' => 'unknown:action:1',
            'from' => ['id' => 999],
            'message' => ['message_id' => 11, 'chat' => ['id' => -300, 'type' => 'supergroup']],
        ],
    ])->assertOk()->assertJson(['skipped' => 'unknown_callback']);

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
        && $request['callback_query_id'] === 'callback-outside-work'
    );
});

it('applies a day-off callback once and ignores a repeated click', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 904]]));

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-23', 'status' => 'pending',
    ]);

    $payload = [
        'callback_query' => [
            'id' => 'callback-3',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => (int) $reviewer->telegram_id, 'first_name' => 'Supervisor'],
            'message' => ['message_id' => 12, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ];

    $this->postJson('/telegram/work-webhook/test-secret', $payload)->assertOk();
    $reviewedAt = $day->refresh()->reviewed_at;
    $this->postJson('/telegram/work-webhook/test-secret', $payload)
        ->assertOk()
        ->assertJson(['skipped' => 'dayoffday_already_reviewed']);

    expect($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved')
        ->and($day->reviewed_at)->toEqual($reviewedAt)
        ->and($day->reviewed_at)->not->toBeNull();
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-3'
    );
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')
        && (string) $httpRequest['chat_id'] === '-100'
        && $httpRequest['message_id'] === 12
        && ($httpRequest['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

it('applies a day-off rejection callback and updates the aggregate request', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 905]]));

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '789', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '987', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Проверка отказа', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-24', 'status' => 'pending',
    ]);

    $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-dayoff-reject',
            'data' => 'dayoffday:reject:'.$day->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => ['message_id' => 21, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk();

    expect($day->refresh()->status)->toBe('rejected')
        ->and($request->refresh()->status)->toBe('rejected');
});

it('answers malformed and unknown request callbacks without changing request state', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-25', 'status' => 'pending',
    ]);

    foreach ([
        ['id' => 'callback-malformed', 'data' => 'dayoffday:approve'],
        ['id' => 'callback-unknown', 'data' => 'unknown:action:'.$day->id],
    ] as $callback) {
        $this->postJson('/telegram/work-webhook/test-secret', [
            'callback_query' => [
                ...$callback,
                'from' => ['id' => 123],
                'message' => ['message_id' => 41, 'chat' => ['id' => -100, 'type' => 'supergroup']],
            ],
        ])->assertOk();
    }

    expect($day->refresh()->status)->toBe('pending')
        ->and($request->refresh()->status)->toBe('pending');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && in_array($httpRequest['callback_query_id'], ['callback-malformed', 'callback-unknown'], true)
    );
});

it('answers a callback when the referenced day does not exist', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-invalid-day',
            'data' => 'dayoffday:approve:999999',
            'from' => ['id' => 123],
            'message' => ['message_id' => 42, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk()->assertJson(['skipped' => 'dayoffday_not_found']);

    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-invalid-day'
    );
});

it('denies an unlinked Telegram reviewer without mutating a day-off request', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-25', 'status' => 'pending',
    ]);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-unauthorized-day',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => 999],
            'message' => ['message_id' => 45, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk()->assertJson(['skipped' => 'reviewer_not_allowed']);

    expect($day->refresh()->status)->toBe('pending')
        ->and($request->refresh()->status)->toBe('pending');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-unauthorized-day'
        && $httpRequest['text'] === 'Недостаточно прав'
    );
});

it('answers technical callback failures without exposing exceptions or retrying the webhook', function () {
    Http::fake(fn () => Http::response(['ok' => true]));
    Schema::drop('day_off_request_days');

    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-storage-failure',
            'data' => 'dayoffday:approve:7',
            'from' => ['id' => 123],
            'message' => ['message_id' => 43, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'handled' => false]);
    expect($response->getContent())->not->toContain('SQLSTATE');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-storage-failure'
        && str_contains($httpRequest['text'], 'Попробуйте ещё раз')
    );
});

it('keeps a committed day-off decision when Telegram cannot edit the original message', function () {
    Queue::fake();
    Http::fake(fn () => Http::response(['ok' => false, 'description' => 'simulated edit failure'], 400));

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-26', 'status' => 'pending',
    ]);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-edit-failure',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => ['message_id' => 44, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk();

    expect($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved');
});

it('polls an allowed work message through the existing ingest path', function () {
    TelegramOperationalTestDatabase::refresh();
    config(['services.telegram.work_allowed_chat_ids' => ['-100']]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnFalse();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramAssistantService::class, $assistant);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [workPollingPayload(701, 201)]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '701')->count())->toBe(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests[0]['allowed_updates'])->toBe(['message', 'edited_message', 'channel_post', 'callback_query'])
        ->and($requests[1]['offset'])->toBe(202);
});

it('processes work callback queries through polling', function () {
    $payload = [
        'update_id' => 202,
        'callback_query' => [
            'id' => 'polled-callback',
            'data' => 'unknown:action:1',
            'from' => ['id' => 999],
            'message' => ['message_id' => 11, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ];
    $pollRequests = 0;
    Http::fake(function (ClientRequest $request) use (&$pollRequests, $payload) {
        if (str_ends_with($request->url(), '/getUpdates')) {
            $pollRequests++;

            return Http::response(['ok' => true, 'result' => $pollRequests === 1 ? [$payload] : []]);
        }

        return Http::response(['ok' => true]);
    });

    expect(Artisan::call('telegram:work-poll'))->toBe(0);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
        && $request['callback_query_id'] === 'polled-callback'
    );
});

it('polls an OI-only district source without invoking the assistant', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.operational_observer_enabled' => true,
        'services.telegram.digest_districts' => [
            'navigli' => [
                'label' => 'Navigli', 'chat_id' => '-200', 'duty_thread_id' => '285365',
                'latitude' => 45.4514, 'longitude' => 9.1749,
            ],
        ],
    ]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->never();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramAssistantService::class, $assistant);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [workPollingPayload(702, 203, '-200')]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0);
    $saved = TelegramMessage::query()->where('message_id', '702')->sole();
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, fn ($job): bool => $job->telegramMessageId === $saved->id);
});

it('ignores irrelevant and disallowed polled work updates safely', function () {
    config([
        'services.telegram.work_allowed_chat_ids' => [],
        'services.telegram.operational_chat_ids' => [],
        'services.telegram.digest_districts' => [],
    ]);
    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldNotReceive('ingest');
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [
            workPollingPayload(703, 205, '-300'),
            ['update_id' => 204, 'poll_answer' => ['poll_id' => 'legacy']],
        ]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(Http::recorded())->toHaveCount(2);
});

it('keeps duplicate polled work messages idempotent', function () {
    TelegramOperationalTestDatabase::refresh();
    config(['services.telegram.work_allowed_chat_ids' => ['-100']]);
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->twice()->andReturnFalse();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramAssistantService::class, $assistant);
    $payload = workPollingPayload(704, 206);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '704')->count())->toBe(1);
});

it('does not acknowledge past a failed work update', function () {
    fakeWorkPollResponses()->push(['ok' => true, 'result' => [
        ['update_id' => 22, 'message' => ['message_id' => 22]],
        ['update_id' => 20, 'message' => ['message_id' => 20]],
        ['update_id' => 21, 'message' => ['message_id' => 21]],
    ]]);

    $processor = Mockery::mock(TelegramWorkWebhookController::class);
    $processor->shouldReceive('processUpdate')->once()->withArgs(fn (array $update): bool => $update['update_id'] === 20)->andReturn(response()->json(['ok' => true]))->ordered();
    $processor->shouldReceive('processUpdate')->once()->withArgs(fn (array $update): bool => $update['update_id'] === 21)->andThrow(new RuntimeException('fixture failure'))->ordered();
    $processor->shouldReceive('processUpdate')->never()->withArgs(fn (array $update): bool => $update['update_id'] === 22);
    $this->app->instance(TelegramWorkWebhookController::class, $processor);

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 21 failed', 'remain unconfirmed')
        ->and(Http::recorded())->toHaveCount(1);
});

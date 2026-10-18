<?php

use App\Http\Controllers\TelegramWorkWebhookController;
use App\Jobs\ProcessTelegramAssistantMessage;
use App\Jobs\ProcessTelegramInstructionAutoReply;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramInstructionAutoReplyService;
use App\Services\Telegram\TelegramOperationalEventObserver;
use App\Services\Telegram\TelegramUpdateIngestService;
use App\Services\Telegram\TelegramUpdatePoller;
use App\Services\Telegram\TelegramWorkActionFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.main_bot_auto_replies_enabled' => true,
        'services.telegram.work_webhook_secret' => 'test-secret',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.chat_id_formweekend' => '-100',
        'services.telegram.thread_id_formweekend' => null,
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

it('returns promptly while a slow Telegram assistant side effect is deferred', function () {
    Bus::fake();
    Http::fake();

    $savedMessage = new TelegramMessage;
    $savedMessage->setConnection('analytics');
    $savedMessage->setRawAttributes(['id' => 505], true);
    $savedMessage->exists = true;

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->once()->andReturn($savedMessage);
    $assistantCalls = 0;
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnTrue();
    $assistant->shouldReceive('handle')->zeroOrMoreTimes()->andReturnUsing(function () use (&$assistantCalls): void {
        $assistantCalls++;
        usleep(1_200_000);
    });
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $startedAt = hrtime(true);
    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 505,
            'date' => now()->timestamp,
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'first_name' => 'Worker', 'is_bot' => false],
            'text' => 'Не работает замок',
        ],
    ]);
    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    $response->assertOk()->assertJsonPath('ok', true);
    expect($elapsedMilliseconds)->toBeLessThan(1_000)
        ->and($assistantCalls)->toBe(0);
    Bus::assertDispatchedAfterResponse(ProcessTelegramAssistantMessage::class, fn ($job): bool => $job->telegramMessageId === 505);
    Http::assertNothingSent();
});

it('returns a retryable failure when the database cannot persist an incoming work message', function () {
    Bus::fake();

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->once()->andThrow(new PDOException('database is locked'));
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnFalse();
    $assistant->shouldNotReceive('handle');
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $startedAt = hrtime(true);
    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 506,
            'date' => now()->timestamp,
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'is_bot' => false],
            'text' => 'Проблема с замком',
        ],
    ]);
    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    $response->assertInternalServerError()->assertJson(['ok' => false]);
    Bus::assertNothingDispatched();
    expect($elapsedMilliseconds)->toBeLessThan(1_000);
});

it('runs deferred assistant processing from the persisted Telegram payload', function () {
    TelegramOperationalTestDatabase::refresh();
    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок',
        sentAt: now('Europe/Rome')->toDateTimeString(),
        messageId: '507',
        raw: ['message' => ['reply_to_message' => ['from' => ['is_bot' => true]]]],
    );
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('handle')->once()->with(
        Mockery::on(fn ($stored): bool => $stored->id === $message->id),
        Mockery::on(fn (array $payload): bool => data_get($payload, 'text') === 'Не работает замок'
            && data_get($payload, 'reply_to_message.from.is_bot') === true),
    );

    (new ProcessTelegramAssistantMessage($message->id))->handle($assistant);
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

it('accepts and idempotently persists POSTs through the named Work API webhook route', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.operational_observer_enabled' => true,
        'services.telegram.operational_chat_ids' => ['-200'],
        'services.telegram.work_allowed_chat_ids' => [],
        'services.telegram.digest_districts' => [],
    ]);
    Queue::fake();
    Http::fake();

    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldNotReceive('isActivated');
    $assistant->shouldNotReceive('handle');
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $route = app('router')->getRoutes()->getByName('telegram.work.webhook');
    $uri = route('telegram.work.webhook', ['secret' => 'test-secret'], false);
    $payload = workPollingPayload(8501, 8501, '-200');

    $this->postJson($uri, $payload)->assertOk()->assertJsonPath('ok', true);
    $this->postJson($uri, $payload)->assertOk()->assertJsonPath('ok', true);

    $message = TelegramMessage::query()->where('message_id', '8501')->sole();

    expect(TelegramMessage::query()->where('message_id', '8501')->count())->toBe(1)
        ->and($message->chat->telegram_chat_id)->toBe('-200')
        ->and($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->uri())->toBe('api/telegram/work-webhook/{secret}')
        ->and($route->gatherMiddleware())->not->toContain('web')
        ->not->toContain('auth');

    Queue::assertPushed(
        ProcessTelegramOperationalMessage::class,
        fn ($job): bool => $job->telegramMessageId === $message->id && $job->mode === 'message',
    );
    Http::assertNothingSent();
});

it('rejects an invalid secret on the named Work API webhook route', function () {
    $uri = route('telegram.work.webhook', ['secret' => 'invalid-secret'], false);

    $this->postJson($uri, workPollingPayload(8502, 8502, '-100'))->assertForbidden();
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

it('uses the IPv6 Telegram API path for direct work-webhook replies', function (): void {
    $telegramOptions = null;
    Http::fake(function (ClientRequest $request, array $options) use (&$telegramOptions) {
        if ($request->toPsrRequest()->getUri()->getHost() === 'api.telegram.org') {
            $telegramOptions = $options;
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 904]]);
    });

    $this->postJson('/telegram/work-webhook/test-secret', privateWebhookPayload(44))->assertOk();

    expect($telegramOptions['force_ip_resolve'] ?? null)->toBe('v6');
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

it('resynchronizes a previously reviewed access request without applying another decision', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => true]));
    $reviewer = User::create(['name' => 'Supervisor', 'telegram_id' => '321', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true]);
    $employee = User::create(['name' => 'Cleaner', 'telegram_id' => '654', 'status' => 'rejected', 'role' => 'cleaner', 'is_active' => true, 'approved_by' => $reviewer->id]);
    $decisionTime = $employee->updated_at->copy();

    $this->postJson('/telegram/work-webhook/test-secret', ['callback_query' => [
        'id' => 'access-repeat', 'data' => 'access:approve:'.$employee->id,
        'from' => ['id' => 321], 'message' => ['message_id' => 20, 'chat' => ['id' => -100, 'type' => 'supergroup']],
    ]])->assertOk()->assertJson(['skipped' => 'access_already_reviewed']);

    expect($employee->fresh()->status)->toBe('rejected')
        ->and($employee->fresh()->updated_at->equalTo($decisionTime))->toBeTrue();
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageText')
        && str_contains((string) $request['text'], 'Доступ отклонён')
        && str_contains((string) $request['text'], 'Supervisor'));
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
        && $httpRequest['text'] === 'Дата одобрена'
    );
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')
        && (string) $httpRequest['chat_id'] === '-100'
        && $httpRequest['message_id'] === 12
        && str_contains($httpRequest['text'], 'Статус заявки:</b> одобрена')
        && str_contains($httpRequest['text'], 'одобрено — Supervisor')
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

    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-dayoff-reject'
        && $httpRequest['text'] === 'Дата отклонена'
    );
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')
        && str_contains($httpRequest['text'], 'Статус заявки:</b> отклонена')
        && str_contains($httpRequest['text'], 'отклонено — Supervisor')
        && ($httpRequest['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

it('keeps only approved dates when Telegram callbacks partially approve a request', function () {
    Http::fake(fn () => Http::response(['ok' => true]));
    Queue::fake();

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Две отдельные даты', 'status' => 'pending',
    ]);
    $approvedDay = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-24', 'status' => 'pending',
    ]);
    $rejectedDay = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-25', 'status' => 'pending',
    ]);

    foreach ([
        ['id' => 'callback-partial-approve', 'action' => 'approve', 'day' => $approvedDay],
        ['id' => 'callback-partial-reject', 'action' => 'reject', 'day' => $rejectedDay],
    ] as $case) {
        $this->postJson('/api/telegram/work-webhook/test-secret', [
            'callback_query' => [
                'id' => $case['id'],
                'data' => 'dayoffday:'.$case['action'].':'.$case['day']->id,
                'from' => ['id' => (int) $reviewer->telegram_id],
                'message' => ['message_id' => 24, 'chat' => ['id' => -100, 'type' => 'supergroup']],
            ],
        ])->assertOk();
    }

    expect($approvedDay->refresh()->status)->toBe('approved')
        ->and($rejectedDay->refresh()->status)->toBe('rejected')
        ->and($request->refresh()->status)->toBe('partially_approved')
        ->and($request->approvedDays()->get()->map(fn (DayOffRequestDay $day): string => $day->date->toDateString())->all())
        ->toBe(['2026-08-24']);
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')
        && str_contains($httpRequest['text'], 'Статус заявки:</b> смешанный результат')
        && ($httpRequest['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

it('accepts a day-off callback from its configured request chat and topic', function () {
    Http::fake(fn () => Http::response(['ok' => true]));
    Queue::fake();
    config([
        'services.telegram.chat_id_formweekend' => '-200',
        'services.telegram.thread_id_formweekend' => '17',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
    ]);

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
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-24', 'status' => 'pending',
    ]);

    $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-request-chat',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => [
                'message_id' => 25,
                'message_thread_id' => 17,
                'chat' => ['id' => -200, 'type' => 'supergroup'],
            ],
        ],
    ])->assertOk();

    expect($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-request-chat'
        && $httpRequest['text'] === 'Дата одобрена'
    );
});

it('accepts an authorized day-off callback when Telegram omits the optional topic field', function () {
    Http::fake(fn () => Http::response(['ok' => true]));
    Queue::fake();
    config([
        'services.telegram.chat_id_formweekend' => '-200',
        'services.telegram.thread_id_formweekend' => '17',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
    ]);

    $reviewer = User::create([
        'name' => 'Admin', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'admin', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-24', 'status' => 'pending',
    ]);

    $this->postJson('/api/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-no-topic-field',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => (int) $reviewer->telegram_id],
            'message' => ['message_id' => 25, 'chat' => ['id' => -200, 'type' => 'supergroup']],
        ],
    ])->assertOk();

    expect($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'callback-no-topic-field'
        && $httpRequest['text'] === 'Дата одобрена'
    );
});

it('rejects day-off callbacks from another chat or topic without changing dates', function () {
    Http::fake(fn () => Http::response(['ok' => true]));
    config([
        'services.telegram.chat_id_formweekend' => '-200',
        'services.telegram.thread_id_formweekend' => '17',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
    ]);

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $days = collect(['2026-08-24', '2026-08-25'])->map(fn (string $date) => DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => $date, 'status' => 'pending',
    ]));

    foreach ([
        ['id' => 'callback-wrong-chat', 'day' => $days[0], 'chat_id' => -100, 'thread_id' => 17],
        ['id' => 'callback-wrong-topic', 'day' => $days[1], 'chat_id' => -200, 'thread_id' => 18],
    ] as $index => $case) {
        $this->postJson('/api/telegram/work-webhook/test-secret', [
            'callback_query' => [
                'id' => $case['id'],
                'data' => 'dayoffday:approve:'.$case['day']->id,
                'from' => ['id' => (int) $reviewer->telegram_id],
                'message' => [
                    'message_id' => 26 + $index,
                    'message_thread_id' => $case['thread_id'],
                    'chat' => ['id' => $case['chat_id'], 'type' => 'supergroup'],
                ],
            ],
        ])->assertOk()->assertJson(['skipped' => 'dayoffday_wrong_destination']);
    }

    expect($days->every(fn (DayOffRequestDay $day): bool => $day->refresh()->status === 'pending'))->toBeTrue();
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
    Http::assertSentCount(1);
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

it('renders each day-off decision with its reviewer and Rome time while retaining only pending buttons', function () {
    Queue::fake();
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 44]]));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 10:30:00', 'UTC'));

    $firstReviewer = User::create(['name' => 'First Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true]);
    $secondReviewer = User::create(['name' => 'Second Supervisor', 'telegram_id' => '124', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true]);
    $employee = User::create(['name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $request = DayOffRequest::create(['user_id' => $employee->id, 'status' => 'pending']);
    $days = collect(['2026-10-20', '2026-10-21', '2026-10-22'])->map(fn (string $date) => DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => $date, 'status' => 'pending',
    ]));

    foreach ([
        ['day' => $days[0], 'action' => 'approve', 'reviewer' => $firstReviewer],
        ['day' => $days[1], 'action' => 'reject', 'reviewer' => $secondReviewer],
    ] as $index => $decision) {
        $this->postJson('/telegram/work-webhook/test-secret', ['callback_query' => [
            'id' => 'decision-'.$index,
            'data' => 'dayoffday:'.$decision['action'].':'.$decision['day']->id,
            'from' => ['id' => (int) $decision['reviewer']->telegram_id],
            'message' => ['message_id' => 44, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ]])->assertOk();
    }

    expect($days[0]->refresh()->reviewed_by)->toBe($firstReviewer->id)
        ->and($days[1]->refresh()->reviewed_by)->toBe($secondReviewer->id)
        ->and($days[2]->refresh()->status)->toBe('pending');

    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')
        && str_contains($httpRequest['text'], 'частично рассмотрена')
        && str_contains($httpRequest['text'], '20.10.2026')
        && str_contains($httpRequest['text'], 'одобрено — First Supervisor, 18.10.2026 12:30')
        && str_contains($httpRequest['text'], 'отклонено — Second Supervisor, 18.10.2026 12:30')
        && str_contains($httpRequest['text'], 'ожидает')
        && count($httpRequest['reply_markup']['inline_keyboard'] ?? []) === 1
        && ($httpRequest['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'dayoffday:approve:'.$days[2]->id
    );
    CarbonImmutable::setTestNow();
});

it('resynchronizes a day-off message after a failed edit without applying a stale decision again', function () {
    Queue::fake();
    $edits = 0;
    Http::fake(function (ClientRequest $httpRequest) use (&$edits) {
        if (str_ends_with($httpRequest->url(), '/editMessageText')) {
            $edits++;

            return Http::response(['ok' => $edits > 1, 'description' => 'simulated'], 200);
        }

        return Http::response(['ok' => true]);
    });

    $reviewer = User::create(['name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true]);
    $employee = User::create(['name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $request = DayOffRequest::create(['user_id' => $employee->id, 'status' => 'pending']);
    $day = DayOffRequestDay::create(['day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-20', 'status' => 'pending']);
    $payload = ['callback_query' => [
        'id' => 'decision-once', 'data' => 'dayoffday:approve:'.$day->id,
        'from' => ['id' => (int) $reviewer->telegram_id],
        'message' => ['message_id' => 44, 'chat' => ['id' => -100, 'type' => 'supergroup']],
    ]];

    $this->postJson('/telegram/work-webhook/test-secret', $payload)->assertOk();
    $reviewedAt = $day->refresh()->reviewed_at;
    $this->postJson('/telegram/work-webhook/test-secret', $payload)
        ->assertOk()->assertJson(['skipped' => 'dayoffday_already_reviewed']);

    expect($edits)->toBe(2)
        ->and($day->refresh()->reviewed_at)->toEqual($reviewedAt)
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

    $instant = CarbonImmutable::parse('2026-07-15 06:00:00', 'UTC');
    $update = workPollingPayload(701, 201);
    $update['message']['date'] = $instant->timestamp;

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [$update]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '701')->count())->toBe(1);

    $stored = TelegramMessage::query()->where('message_id', '701')->sole();
    expect($stored->getRawOriginal('sent_at'))->toBe('2026-07-15 06:00:00')
        ->and($stored->sent_at->timestamp)->toBe($instant->timestamp)
        ->and($stored->sent_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 08:00:00');

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests[0]['allowed_updates'])->toBe(['message', 'edited_message', 'channel_post', 'callback_query'])
        ->and($requests[1]['offset'])->toBe(202);
});

it('stores an old work update for OI without queuing stale outgoing answers', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.operational_observer_enabled' => true,
        'services.telegram.work_action_max_age_minutes' => 30,
    ]);
    Bus::fake();
    Http::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->twice()->andReturnTrue();
    $this->app->instance(TelegramAssistantService::class, $assistant);
    $update = workPollingPayload(715, 215);
    $update['message']['date'] = now()->subDay()->timestamp;
    $update['message']['text'] = '/help полотенце';

    $this->postJson('/telegram/work-webhook/test-secret', $update)->assertOk();
    $this->postJson('/telegram/work-webhook/test-secret', $update)->assertOk();

    $stored = TelegramMessage::query()->where('message_id', '715')->sole();
    Bus::assertDispatched(ProcessTelegramOperationalMessage::class, fn ($job): bool => $job->telegramMessageId === $stored->id);
    Bus::assertNotDispatched(ProcessTelegramAssistantMessage::class);
    Bus::assertNotDispatchedAfterResponse(ProcessTelegramAssistantMessage::class);
    Bus::assertNotDispatched(ProcessTelegramInstructionAutoReply::class);
    Http::assertNothingSent();
});

it('records callbacks for manual reconciliation while backlog protection is enabled', function () {
    config(['services.telegram.work_backlog_callback_guard_enabled' => true]);
    Http::fake();
    Log::spy();
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create(['user_id' => $employee->id, 'status' => 'pending']);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-18', 'status' => 'pending',
    ]);

    $this->postJson('/telegram/work-webhook/test-secret', [
        'update_id' => 216,
        'callback_query' => [
            'id' => 'backlog-callback',
            'data' => 'dayoffday:approve:'.$day->id,
            'from' => ['id' => 123],
            'message' => ['message_id' => 44, 'date' => now()->timestamp, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ])->assertOk()->assertJsonPath('skipped', 'manual_reconciliation_required');

    expect($day->refresh()->status)->toBe('pending');
    Log::shouldHaveReceived('warning')->with('telegram_work_manual_reconciliation_required', Mockery::on(
        fn (array $context): bool => $context['update_id'] === 216
            && $context['callback_type'] === 'dayoffday'
            && $context['callback_target_id'] === $day->id,
    ));
    Http::assertNothingSent();
});

it('handles and acknowledges new day-off callbacks after the backlog guard is disabled', function () {
    config(['services.telegram.work_backlog_callback_guard_enabled' => false]);
    Queue::fake();

    $reviewer = User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = DayOffRequest::create(['user_id' => $employee->id, 'status' => 'pending']);
    $day = DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-10-18', 'status' => 'pending',
    ]);

    $callback = fn (string $id): array => [
        'id' => $id,
        'data' => 'dayoffday:approve:'.$day->id,
        'from' => ['id' => (int) $reviewer->telegram_id],
        'message' => ['message_id' => 44, 'chat' => ['id' => -100, 'type' => 'supergroup']],
    ];
    $pollRequests = 0;
    Http::fake(function (ClientRequest $httpRequest) use (&$pollRequests, $callback) {
        if (str_ends_with($httpRequest->url(), '/getUpdates')) {
            $pollRequests++;

            return Http::response(['ok' => true, 'result' => $pollRequests === 1 ? [
                ['update_id' => 302, 'callback_query' => $callback('fresh-click')],
                ['update_id' => 303, 'callback_query' => $callback('repeated-click')],
            ] : []]);
        }

        return Http::response(['ok' => true]);
    });

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved')
        ->and($day->reviewed_by)->toBe($reviewer->id)
        ->and($day->reviewed_at)->not->toBeNull();

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests->filter(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/editMessageText')))->toHaveCount(2)
        ->and($requests->filter(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')))->toHaveCount(2);
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'fresh-click'
        && $httpRequest['text'] === 'Дата одобрена');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/answerCallbackQuery')
        && $httpRequest['callback_query_id'] === 'repeated-click'
        && $httpRequest['text'] === 'Дата уже одобрена');
    Http::assertSent(fn (ClientRequest $httpRequest): bool => str_ends_with($httpRequest->url(), '/getUpdates')
        && ($httpRequest->data()['offset'] ?? null) === 304
        && $httpRequest['allowed_updates'] === ['message', 'edited_message', 'channel_post', 'callback_query']);
});

it('long polls for fresh callbacks and carries the acknowledged offset into the next fetch', function () {
    $calls = 0;
    Http::fake(function (ClientRequest $request) use (&$calls) {
        $calls++;

        return Http::response(['ok' => true, 'result' => $calls === 1 ? [
            ['update_id' => 501, 'callback_query' => ['id' => 'fresh-callback']],
        ] : []]);
    });
    $processed = [];

    $result = app(TelegramUpdatePoller::class)->poll(
        'test-token',
        ['callback_query'],
        50,
        function (array $update) use (&$processed): void {
            $processed[] = $update['update_id'];
        },
        offset: 500,
        waitSeconds: 15,
    );

    expect($result)->toBe(['processed' => 1, 'last_update_id' => 501])
        ->and($processed)->toBe([501]);
    $requests = Http::recorded()->pluck(0)->values();
    expect($requests[0]['offset'])->toBe(500)
        ->and($requests[0]['timeout'])->toBe(15)
        ->and($requests[1]['offset'])->toBe(502)
        ->and($requests[1]['timeout'])->toBe(0);
});

it('uses the configured Telegram IP family for polling requests', function () {
    config(['services.telegram.ip_resolve' => 'v6']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $options = null;
    Http::macro('withOptions', function (array $provided) use (&$options) {
        $options = $provided;

        return $this->createPendingRequest()->withOptions($provided);
    });

    try {
        app(TelegramUpdatePoller::class)->poll('test-token', ['callback_query'], 1, fn () => null);

        expect($options)->toBe(['force_ip_resolve' => 'v6']);
    } finally {
        Http::flushMacros();
    }
});

it('runs a bounded Work listener with callback updates enabled', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    expect(Artisan::call('telegram:work-poll', ['--listen-seconds' => 1]))->toBe(0);
    expect(Http::recorded()->count())->toBeGreaterThan(0)->toBeLessThan(6);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/getUpdates')
        && $request['timeout'] === 1
        && in_array('callback_query', $request['allowed_updates'], true));
});

it('stores an old private update without sending the expired fallback reply', function () {
    TelegramOperationalTestDatabase::refresh();
    Http::fake();
    $update = privateWebhookPayload(716);
    $update['message']['date'] = now()->subHours(2)->timestamp;

    $this->postJson('/telegram/work-webhook/test-secret', $update)
        ->assertOk()->assertJsonPath('skipped', 'manual_reconciliation_required');

    expect(TelegramMessage::query()->where('message_id', '716')->count())->toBe(1);
    Http::assertNothingSent();
});

it('uses the configured age boundary for Work outgoing actions', function () {
    config(['services.telegram.work_action_max_age_minutes' => 30]);
    $freshness = app(TelegramWorkActionFreshness::class);

    expect($freshness->messageIsCurrent(['date' => now()->subMinutes(29)->timestamp]))->toBeTrue()
        ->and($freshness->messageIsCurrent(['date' => now()->subMinutes(31)->timestamp]))->toBeFalse()
        ->and($freshness->messageIsCurrent(['date' => now()->addMinutes(2)->timestamp]))->toBeFalse()
        ->and($freshness->messageIsCurrent([]))->toBeFalse();
});

it('keeps callback actions paused while the recovery guard is absent from an old config cache', function () {
    $telegramConfig = config('services.telegram');
    unset($telegramConfig['work_backlog_callback_guard_enabled']);
    config(['services.telegram' => $telegramConfig]);

    expect(app(TelegramWorkActionFreshness::class)->callbackRequiresReconciliation())->toBeTrue();
});

it('persists activated work assistant processing before polling confirms the update', function () {
    TelegramOperationalTestDatabase::refresh();
    config(['services.telegram.work_allowed_chat_ids' => ['-100']]);
    Bus::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnTrue();
    $assistant->shouldNotReceive('handle');
    $this->app->instance(TelegramAssistantService::class, $assistant);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [workPollingPayload(705, 207)]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0);
    $message = TelegramMessage::query()->where('message_id', '705')->sole();

    Bus::assertDispatched(ProcessTelegramAssistantMessage::class, fn ($job): bool => $job->telegramMessageId === $message->id);
    Bus::assertNotDispatchedAfterResponse(ProcessTelegramAssistantMessage::class);
});

it('does not mark an Assistant job successful when its handler fails', function () {
    TelegramOperationalTestDatabase::refresh();
    $message = TelegramOperationalTestDatabase::message('Нужна помощь', sentAt: now('Europe/Rome')->toDateTimeString(), messageId: '708');
    $message->forceFill(['raw' => ['message' => [
        'message_id' => 708,
        'date' => now()->timestamp,
        'text' => 'Нужна помощь',
        'chat' => ['id' => -100, 'type' => 'supergroup'],
    ]]])->save();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('handle')->once()->andThrow(new RuntimeException('simulated assistant failure'));

    expect(fn () => (new ProcessTelegramAssistantMessage($message->id))->handle($assistant))
        ->toThrow(RuntimeException::class);
});

it('does not send a queued Assistant or instruction reply after its message becomes stale', function () {
    TelegramOperationalTestDatabase::refresh();
    config(['services.telegram.work_action_max_age_minutes' => 30]);
    $message = TelegramOperationalTestDatabase::message('/help полотенце', messageId: '709');
    $message->forceFill(['raw' => ['update_id' => 217, 'message' => [
        'message_id' => 709,
        'date' => now()->subHour()->timestamp,
        'text' => '/help полотенце',
        'chat' => ['id' => -100, 'type' => 'supergroup'],
    ]]])->save();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldNotReceive('handle');
    $instruction = Mockery::mock(TelegramInstructionAutoReplyService::class);
    $instruction->shouldNotReceive('handle');

    (new ProcessTelegramAssistantMessage($message->id))->handle($assistant);
    (new ProcessTelegramInstructionAutoReply($message->id))->handle($instruction);
});

it('keeps an activated work update pending if its assistant job cannot be queued', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.operational_observer_enabled' => false,
        'queue.default' => 'unavailable-test-queue',
    ]);
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->once()->andReturnTrue();
    $assistant->shouldNotReceive('handle');
    $this->app->instance(TelegramAssistantService::class, $assistant);

    fakeWorkPollResponses()->push(['ok' => true, 'result' => [workPollingPayload(706, 209)]]);

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 209 failed')
        ->and(TelegramMessage::query()->where('message_id', '706')->count())->toBe(1)
        ->and(Http::recorded())->toHaveCount(1);
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

it('does not confirm a polled callback when its database operation fails', function () {
    Schema::drop('day_off_request_days');
    $pollRequests = 0;
    Http::fake(function (ClientRequest $request) use (&$pollRequests) {
        if (str_ends_with($request->url(), '/getUpdates')) {
            $pollRequests++;

            return Http::response(['ok' => true, 'result' => [[
                'update_id' => 208,
                'callback_query' => [
                    'id' => 'polled-storage-failure',
                    'data' => 'dayoffday:approve:7',
                    'from' => ['id' => 123],
                    'message' => ['message_id' => 43, 'chat' => ['id' => -100, 'type' => 'supergroup']],
                ],
            ]]]);
        }

        return Http::response(['ok' => true]);
    });

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 208 failed')
        ->and($pollRequests)->toBe(1);
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
    config([
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->twice()->andReturnFalse();
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramAssistantService::class, $assistant);
    $payload = workPollingPayload(704, 206);

    $sameMessageNewUpdate = $payload;
    $sameMessageNewUpdate['update_id'] = 207;

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$sameMessageNewUpdate]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0);

    $message = TelegramMessage::query()->where('message_id', '704')->sole();
    app(TelegramOperationalEventObserver::class)->observe($message);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '704')->count())->toBe(1);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 1);
});

it('keeps OI work recoverable when an observation is missing or the message is edited', function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.work_allowed_chat_ids' => ['-100'],
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->times(3)->andReturnFalse();
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $original = workPollingPayload(706, 208);
    $edited = $original;
    $edited['update_id'] = 209;
    $edited['edited_message'] = $edited['message'];
    $edited['edited_message']['text'] = 'Замок снова не работает';
    $edited['edited_message']['edit_date'] = now()->timestamp;
    unset($edited['message']);

    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => [$original]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$original]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$edited]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(Artisan::call('telegram:work-poll'))->toBe(0);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 2);

    $message = TelegramMessage::query()->where('message_id', '706')->sole();
    app(TelegramOperationalEventObserver::class)->observe($message);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and($message->fresh()->text)->toBe('Замок снова не работает');
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 3);
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

it('does not start a competing work poller while another invocation holds the lock', function () {
    $lock = Cache::lock('telegram-work-poll', 900);
    expect($lock->get())->toBeTrue();

    try {
        Http::fake();

        expect(Artisan::call('telegram:work-poll'))->toBe(1)
            ->and(Http::recorded())->toHaveCount(0);
    } finally {
        $lock->release();
    }
});

it('recovers a stored work message after the acknowledgement request times out', function () {
    TelegramOperationalTestDatabase::refresh();
    config(['services.telegram.work_allowed_chat_ids' => ['-100']]);
    Queue::fake();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->twice()->andReturnFalse();
    $this->app->instance(TelegramAssistantService::class, $assistant);
    $update = workPollingPayload(707, 210);
    $calls = 0;
    Http::fake(function (ClientRequest $request) use (&$calls, $update) {
        $calls++;

        if ($calls === 2) {
            throw new ConnectionException('simulated timeout');
        }

        return Http::response(['ok' => true, 'result' => $calls === 3 ? [$update] : ($calls === 4 ? [] : [$update])]);
    });

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('acknowledgement status is uncertain')
        ->and(TelegramMessage::query()->where('message_id', '707')->count())->toBe(1)
        ->and(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '707')->count())->toBe(1)
        ->and($calls)->toBe(4);
});

it('stops on a locked database update without confirming it or later updates', function () {
    fakeWorkPollResponses()->push(['ok' => true, 'result' => [
        ['update_id' => 211, 'message' => ['message_id' => 711]],
        ['update_id' => 212, 'message' => ['message_id' => 712]],
    ]]);
    $processor = Mockery::mock(TelegramWorkWebhookController::class);
    $processor->shouldReceive('processUpdate')->once()->andThrow(new QueryException(
        'sqlite', 'insert into jobs', [], new PDOException('database is locked'),
    ));
    $this->app->instance(TelegramWorkWebhookController::class, $processor);

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 211 failed')
        ->and(Http::recorded())->toHaveCount(1);
});

it('keeps a large Work backlog bounded to one requested batch and one acknowledgement item', function () {
    $updates = collect(range(1, 50))->map(fn (int $id): array => [
        'update_id' => $id,
        'poll_answer' => ['poll_id' => (string) $id],
    ])->all();
    fakeWorkPollResponses()
        ->push(['ok' => true, 'result' => $updates])
        ->push(['ok' => true, 'result' => [['update_id' => 51, 'poll_answer' => ['poll_id' => '51']]]]);

    expect(Artisan::call('telegram:work-poll'))->toBe(0)
        ->and(Artisan::output())->toContain('51 update(s) processed')
        ->and(Http::recorded())->toHaveCount(2);
    $requests = Http::recorded()->pluck(0)->values();
    expect($requests[0]['limit'])->toBe(50)
        ->and($requests[1]['limit'])->toBe(1)
        ->and($requests[1]['offset'])->toBe(51);
});

it('reports a Telegram API failure without confirming any Work update', function () {
    Log::shouldReceive('warning')->once()->with('Work Telegram polling failed', Mockery::on(
        fn (array $context): bool => $context['stage'] === 'fetch'
            && $context['exception'] === 'RuntimeException',
    ));
    Http::fake(fn () => Http::response(['ok' => false, 'description' => 'simulated failure'], 503));

    expect(Artisan::call('telegram:work-poll'))->toBe(1)
        ->and(Http::recorded())->toHaveCount(1);
});

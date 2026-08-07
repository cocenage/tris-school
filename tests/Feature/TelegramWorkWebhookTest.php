<?php

use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramUpdateIngestService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.work_webhook_secret' => 'test-secret',
        'services.telegram.work_allowed_chat_ids' => ['-100'],
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
});

afterEach(function () {
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

    $reviewer = \App\Models\User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = \App\Models\User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-1',
            'data' => 'access:approve:' . $employee->id,
            'from' => ['id' => (int) $reviewer->telegram_id, 'first_name' => 'Supervisor'],
            'message' => ['message_id' => 10, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($employee->refresh()->status)->toBe('approved');
    Http::assertSent(fn (ClientRequest $request): bool =>
        str_ends_with($request->url(), '/answerCallbackQuery')
        && $request['callback_query_id'] === 'callback-1'
    );
    Http::assertSent(fn (ClientRequest $request): bool =>
        str_ends_with($request->url(), '/editMessageText')
        && ($request['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

it('rejects a callback from a Telegram user without an approved mapping', function () {
    Http::fake(fn () => Http::response(['ok' => true]));

    $employee = \App\Models\User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'pending', 'role' => 'cleaner', 'is_active' => true,
    ]);

    $response = $this->postJson('/telegram/work-webhook/test-secret', [
        'callback_query' => [
            'id' => 'callback-2',
            'data' => 'access:approve:' . $employee->id,
            'from' => ['id' => 999],
            'message' => ['message_id' => 11, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ]);

    $response->assertOk()->assertJson(['skipped' => 'reviewer_not_allowed']);
    expect($employee->refresh()->status)->toBe('pending');
});

it('applies a day-off callback once and ignores a repeated click', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 904]]));

    $reviewer = \App\Models\User::create([
        'name' => 'Supervisor', 'telegram_id' => '123', 'status' => 'approved', 'role' => 'supervisor', 'is_active' => true,
    ]);
    $employee = \App\Models\User::create([
        'name' => 'Cleaner', 'telegram_id' => '456', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true,
    ]);
    $request = \App\Models\DayOffRequest::create([
        'user_id' => $employee->id, 'reason' => 'Нужен выходной', 'status' => 'pending',
    ]);
    $day = \App\Models\DayOffRequestDay::create([
        'day_off_request_id' => $request->id, 'user_id' => $employee->id, 'date' => '2026-08-23', 'status' => 'pending',
    ]);

    $payload = [
        'callback_query' => [
            'id' => 'callback-3',
            'data' => 'dayoffday:approve:' . $day->id,
            'from' => ['id' => (int) $reviewer->telegram_id, 'first_name' => 'Supervisor'],
            'message' => ['message_id' => 12, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ],
    ];

    $this->postJson('/telegram/work-webhook/test-secret', $payload)->assertOk();
    $this->postJson('/telegram/work-webhook/test-secret', $payload)
        ->assertOk()
        ->assertJson(['skipped' => 'dayoffday_already_reviewed']);

    expect($day->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('approved')
        ->and($day->reviewed_at)->not->toBeNull();
});

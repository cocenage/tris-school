<?php

use App\Filament\Resources\TelegramScheduledMessages\Pages\CreateTelegramScheduledMessage;
use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use App\Jobs\DeliverScheduledControlFollowup;
use App\Jobs\DeliverScheduledTelegramMessage;
use App\Models\ShiftDuty;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramTopic;
use App\Models\User;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDestinationCatalog;
use App\Services\Telegram\TelegramScheduledControlFollowupService;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Mockery\MockInterface;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'app.timezone' => 'Europe/Rome',
        'queue.default' => 'database',
        'services.telegram.main_bot_auto_replies_enabled' => false,
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.analytics.database' => ':memory:',
    ]);

    DB::purge('sqlite');
    DB::purge('analytics');

    $migration = require base_path('database/migrations/2026_09_27_000000_create_telegram_scheduled_messages_tables.php');
    $migration->up();
    (require base_path('database/migrations/2026_10_01_020000_create_scheduled_control_response_tables.php'))->up();
    (require base_path('database/migrations/2026_10_18_000000_add_district_answers_to_scheduled_controls.php'))->up();
    (require database_path('migrations/2026_10_27_000000_add_scheduled_control_followups.php'))->up();
    (require database_path('migrations/2026_11_01_000000_add_sequential_check_state_to_scheduled_deliveries.php'))->up();
    $queueMigration = require base_path('database/migrations/0001_01_01_000002_create_jobs_table.php');
    $queueMigration->up();

    Schema::connection('analytics')->create('telegram_chats', function (Blueprint $table): void {
        $table->id();
        $table->string('telegram_chat_id')->unique();
        $table->string('title')->nullable();
        $table->string('type')->nullable();
        $table->boolean('is_enabled')->default(true);
        $table->timestamps();
    });

    Schema::connection('analytics')->create('telegram_topics', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('telegram_chat_id');
        $table->string('telegram_thread_id');
        $table->string('title')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->boolean('is_enabled')->default(true);
        $table->timestamps();
    });

    Carbon::setTestNow(Carbon::parse('2026-09-28 11:00:20', 'Europe/Rome'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    DB::purge('sqlite');
    DB::purge('analytics');
});

function makeScheduledTelegramMessage(array $overrides = []): TelegramScheduledMessage
{
    $chat = TelegramChat::query()->firstOrCreate(
        ['telegram_chat_id' => '-100000000001'],
        ['title' => 'Test work chat', 'type' => 'supergroup', 'is_enabled' => true],
    );
    $topic = TelegramTopic::query()->firstOrCreate(
        ['telegram_chat_id' => $chat->getKey(), 'telegram_thread_id' => '42'],
        ['title' => 'Test topic', 'is_enabled' => true],
    );

    return TelegramScheduledMessage::query()->create(array_replace([
        'name' => 'Test control',
        'control_type' => 'first_cleanings_started',
        'telegram_chat_record_id' => $chat->getKey(),
        'telegram_topic_record_id' => $topic->getKey(),
        'message' => '🔴 Проверка уборок',
        'send_time' => '11:00:00',
        'weekdays' => [1],
        'enabled' => true,
    ], $overrides));
}

it('queues one HTML OKS opening in the existing control topic and saves assignments from the Work webhook', function (): void {
    (require database_path('migrations/2026_10_03_000000_allow_replyless_scheduled_control_responses.php'))->up();
    (require database_path('migrations/2026_10_14_000000_support_scheduled_control_button_responses.php'))->up();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });
    (require database_path('migrations/2026_11_15_000000_create_shift_duties_table.php'))->up();
    config([
        'services.telegram.bot_token' => 'test-work',
        'services.telegram.work_webhook_secret' => 'test-opening',
        'services.telegram.rich_messages_enabled' => true,
        'services.telegram.oks_shift_opening_time' => '09:00',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-09-28 09:00:20', 'Europe/Rome'));
    $anchor = makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    $admin = DB::table('users')->insertGetId(['name' => 'Админ', 'role' => 'admin', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '101']);
    $supervisor = DB::table('users')->insertGetId(['name' => 'Дежурный', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '102']);
    Queue::fake();
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/sendRichMessage')) {
            throw new ConnectionException('Rich endpoint connection failed');
        }

        $opening = str_contains((string) ($request['text'] ?? ''), 'Открытие смены');

        return Http::response(['ok' => true, 'result' => ['message_id' => $opening ? 123 : 124]]);
    });

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $delivery = TelegramScheduledMessageDelivery::sole();
    expect($delivery->control_type)->toBe('oks_shift_opening')
        ->and($delivery->scheduled_message_id)->toBe($anchor->id)
        ->and($delivery->message_thread_id)->toBe('42');
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
    (new DeliverScheduledTelegramMessage($delivery->id, now()->toImmutable()->addHour()))->handle(app(TelegramBotService::class));
    (new DeliverScheduledTelegramMessage($delivery->id, now()->toImmutable()->addHour()))->handle(app(TelegramBotService::class));
    Http::assertSentCount(1);
    $sent = Http::recorded()->sole()[0];
    expect($sent->url())->toEndWith('/sendMessage')
        ->and($sent['message_thread_id'])->toBe(42)
        ->and($sent['parse_mode'])->toBe('HTML')
        ->and($sent['text'])->toContain('Открытие смены')
        ->and($delivery->refresh()->status)->toBe('sent');

    $click = function (string $action, int $actor = 101, int $thread = 42) use ($delivery): void {
        static $id = 0;
        $this->postJson('/telegram/work-webhook/test-opening', ['callback_query' => [
            'id' => (string) ++$id, 'from' => ['id' => $actor, 'is_bot' => false],
            'data' => 'oks:'.$delivery->id.':'.$action,
            'message' => ['message_id' => 123, 'message_thread_id' => $thread, 'chat' => ['id' => -100000000001, 'type' => 'supergroup']],
        ]])->assertOk();
    };
    $click('nav:0');
    $click('set:navigli:'.$supervisor, 102);
    $click('set:navigli:'.$supervisor, 101, 44);
    expect($delivery->refresh()->sequence_context['opening_draft'] ?? [])->toBe([]);
    $click('set:navigli:'.$supervisor);
    $click('save');
    expect(ShiftDuty::count())->toBe(0);
    foreach (['lambrate', 'lodi', 'certosa', 'como'] as $district) {
        $click('set:'.$district.':0');
    }
    $click('save');
    $click('save');
    expect(ShiftDuty::sole()->user_id)->toBe($supervisor)
        ->and($delivery->refresh()->sequence_context['opening_confirmed_by'])->toBe($admin)
        ->and($delivery->sequence_context['opening_active_districts'])->toBe(['navigli']);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText') && $request['message_id'] === 123
        && ($request['parse_mode'] ?? null) === 'HTML'
        && str_contains((string) ($request['text'] ?? ''), 'Назначения сохранены'));

    Carbon::setTestNow(Carbon::parse('2026-09-28 09:30:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $control = TelegramScheduledMessageDelivery::query()->where('control_type', 'schedule_checked')->sole();
    (new DeliverScheduledTelegramMessage($control->id, now()->toImmutable()->addHour()))->handle(app(TelegramBotService::class));
    expect($control->refresh()->status)->toBe('sent')
        ->and($control->sequence_context['oks_assignments'])->toBe(['navigli' => $supervisor]);
    $controlSend = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
        && ! str_contains((string) ($request['text'] ?? ''), 'Открытие смены'))->sole()[0];
    $buttons = collect($controlSend['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
    expect($buttons)->toBe(['sc:'.$control->id.':navigli:yes', 'sc:'.$control->id.':navigli:no']);

    foreach ([['navigli', 101], ['como', 102], ['navigli', 102]] as $index => [$district, $actor]) {
        $this->postJson('/telegram/work-webhook/test-opening', ['callback_query' => [
            'id' => 'control-'.$index, 'from' => ['id' => $actor, 'is_bot' => false],
            'data' => 'sc:'.$control->id.':'.$district.':yes',
            'message' => ['message_id' => 124, 'message_thread_id' => 42, 'chat' => ['id' => -100000000001, 'type' => 'supergroup']],
        ]])->assertOk()->assertJsonPath('callback_status', $index === 2 ? 'saved' : 'unmatched');
    }
    $answer = $control->responses()->sole();
    expect($answer->user_id)->toBe($supervisor)->and($answer->district_key)->toBe('navigli');

    $replies = app(TelegramScheduledControlResponseService::class);
    $stored = new TelegramMessage;
    $stored->id = 1;
    $stored->setRelation('telegramUser', null);
    $reply = [
        'message_id' => 125, 'message_thread_id' => 42, 'chat' => ['id' => -100000000001, 'type' => 'supergroup'],
        'from' => ['id' => 101], 'reply_to_message' => ['message_id' => 124], 'date' => now()->timestamp,
        'text' => "Navigli: Нет\nComo: Нет",
    ];
    expect($replies->capture($stored, $reply))->toBeNull();
    $reply['from']['id'] = 102;
    expect($replies->capture($stored, $reply))->toBeNull();
    $reply['text'] = 'Да';
    $textAnswer = $replies->capture($stored, $reply);
    expect($textAnswer?->user_id)->toBe($supervisor)->and($textAnswer?->district_key)->toBe('navigli');
});

it('reserves one due occurrence and queues it without calling Telegram in the command', function (): void {
    makeScheduledTelegramMessage();
    Queue::fake();
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendScheduledMessage'));

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 1; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    $delivery = TelegramScheduledMessageDelivery::query()->sole();
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, fn (DeliverScheduledTelegramMessage $job): bool => $job->deliveryId === $delivery->id);

    expect($delivery->toArray())
        ->toMatchArray([
            'control_type' => 'first_cleanings_started',
            'chat_id' => '-100000000001',
            'message_thread_id' => '42',
            'telegram_message_id' => null,
            'status' => 'pending',
        ]);
});

it('does not send a future, disabled, or wrong-weekday message', function (): void {
    makeScheduledTelegramMessage(['name' => 'Future', 'send_time' => '12:00:00']);
    makeScheduledTelegramMessage(['name' => 'Disabled', 'enabled' => false]);
    makeScheduledTelegramMessage(['name' => 'Wrong weekday', 'weekdays' => [2]]);
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('respects configured weekdays when the message is due at the current minute', function (): void {
    makeScheduledTelegramMessage(['weekdays' => [2]]);
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:20', 'Europe/Rome'));
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('queued: 1')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->sole()->status)->toBe('pending');
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
});

it('uses the configured second-cleaning time and never schedules closing payments by time', function (): void {
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'name' => 'All cleanings finished', 'send_time' => '17:30:00', 'weekdays' => [1],
    ]);
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::SECOND_CLEANINGS_FINISHING,
        'name' => 'Second cleanings finishing', 'send_time' => '14:00:00', 'weekdays' => [1],
    ]);
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::CLOSING_PAYMENTS,
        'name' => 'All extra payments completed', 'send_time' => '18:00:00', 'weekdays' => [1],
    ]);
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-09-28 13:59:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    Carbon::setTestNow(Carbon::parse('2026-09-28 14:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 1; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    $secondCleaning = TelegramScheduledMessageDelivery::query()->sole();
    expect($secondCleaning->control_type)->toBe(ScheduledControlTypes::SECOND_CLEANINGS_FINISHING)
        ->and($secondCleaning->scheduled_for->format('Y-m-d H:i:s'))->toBe('2026-09-28 14:00:00');

    Carbon::setTestNow(Carbon::parse('2026-09-28 14:02:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 0; failed: 0; duplicate: 1')
        ->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-09-28 17:30:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 1; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-09-28 18:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-09-28 19:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->pluck('control_type')->all())
        ->toBe([ScheduledControlTypes::SECOND_CLEANINGS_FINISHING, ScheduledControlTypes::CLOSING_CLEANINGS])
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 2);
});

it('applies the selected weekday to the configured second-cleaning time', function (): void {
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::SECOND_CLEANINGS_FINISHING,
        'send_time' => '14:00:00',
        'weekdays' => [2],
    ]);
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-09-28 14:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-09-29 14:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 1; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->sole()->scheduled_for->format('Y-m-d H:i:s'))
        ->toBe('2026-09-29 14:00:00');
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
});

it('keeps a configured local delivery time across Europe Rome daylight saving transitions', function (string $date, string $offset): void {
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::SECOND_CLEANINGS_FINISHING,
        'send_time' => '14:00:00',
        'weekdays' => [7],
    ]);
    Carbon::setTestNow(Carbon::parse($date.' 14:00:20', 'Europe/Rome'));
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 1; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    $delivery = TelegramScheduledMessageDelivery::query()->sole();
    $scheduledAt = Carbon::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome');

    expect($scheduledAt->format('Y-m-d H:i:s'))->toBe($date.' 14:00:00')
        ->and($scheduledAt->format('P'))->toBe($offset);
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
})->with([
    'spring clock change' => ['2026-03-29', '+02:00'],
    'autumn clock change' => ['2026-10-25', '+01:00'],
]);

it('adds five district answer rows to a control and no buttons to an informational message', function (): void {
    makeScheduledTelegramMessage([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'message' => 'All cleanings finished?', 'send_time' => '19:00:00', 'weekdays' => [1],
    ]);
    makeScheduledTelegramMessage([
        'control_type' => 'reminder',
        'send_time' => '19:00:00', 'weekdays' => [1],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-09-28 19:00:20', 'Europe/Rome'));
    Queue::fake();
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 5690]], 200)]);

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    foreach (Queue::pushed(DeliverScheduledTelegramMessage::class) as $job) {
        $job->handle(app(TelegramBotService::class));
    }

    $requests = Http::recorded()->pluck(0)->values();
    $closingRequest = $requests->first(fn ($request): bool => data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Lambrate · ✅ Да');
    $ordinaryRequest = $requests->first(fn ($request): bool => data_get($request->data(), 'text') === '🔴 Проверка уборок');

    expect($closingRequest)->not->toBeNull()
        ->and(data_get($closingRequest->data(), 'reply_markup.inline_keyboard.0.1.text'))->toBe('Lambrate · ❌ Нет')
        ->and(data_get($closingRequest->data(), 'reply_markup.inline_keyboard.0.0.callback_data'))->toBe('sc:'.TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_CLEANINGS)->value('id').':lambrate:yes')
        ->and(count(data_get($closingRequest->data(), 'reply_markup.inline_keyboard')))->toBe(5)
        ->and(data_get($ordinaryRequest?->data() ?? [], 'reply_markup'))->toBeNull();
});

it('does not attempt the same scheduled occurrence twice', function (): void {
    makeScheduledTelegramMessage(['send_time' => '10:59:00']);
    Queue::fake();
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 5680]], 200)]);

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $job = Queue::pushed(DeliverScheduledTelegramMessage::class)->sole();
    $job->handle(app(TelegramBotService::class));
    $job->handle(app(TelegramBotService::class));
    Carbon::setTestNow(Carbon::parse('2026-09-28 11:01:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('duplicate: 1')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(1)
        ->and(TelegramScheduledMessageDelivery::query()->sole()->status)->toBe('sent');
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
    Http::assertSentCount(1);
});

it('retries a connection exception and marks the same delivery sent on a later attempt', function (): void {
    makeScheduledTelegramMessage();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;

        if ($attempt === 1) {
            throw new ConnectionException('Temporary network failure');
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 5685]], 200);
    });

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $delivery = TelegramScheduledMessageDelivery::query()->sole();
    $deliveryId = $delivery->id;

    expect(DB::table('jobs')->count())->toBe(1);
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    $queuedJob = unserialize($payload['data']['command']);
    expect($queuedJob)->toBeInstanceOf(DeliverScheduledTelegramMessage::class)
        ->and($queuedJob->deliveryId)->toBe($deliveryId)
        ->and($queuedJob->tries)->toBe(8)
        ->and($queuedJob->backoff())->toBe([30, 60, 120, 300, 600, 600, 600])
        ->and($queuedJob->retryUntilAt->equalTo($delivery->scheduled_for->copy()->addHour()))->toBeTrue();

    $workerOptions = [
        'connection' => 'database',
        '--queue' => 'default',
        '--once' => true,
        '--tries' => 8,
        '--timeout' => 30,
        '--sleep' => 0,
    ];
    $this->artisan('queue:work', $workerOptions)->assertExitCode(0);

    expect($delivery->fresh()->toArray())->toMatchArray([
        'status' => 'retrying',
        'failure_reason' => 'connection_exception',
    ]);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-09-28 11:00:51', 'Europe/Rome'));
    $this->artisan('queue:work', $workerOptions)->assertExitCode(0);
    $delivery->refresh();

    expect($delivery->status)->toBe('sent')
        ->and($delivery->telegram_message_id)->toBe(5685)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->id)->toBe($deliveryId);
    expect($attempt)->toBe(2);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('retries transient Telegram server responses and completes the original delivery later', function (): void {
    makeScheduledTelegramMessage();
    Queue::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fakeSequence()
        ->push(['ok' => false], 503)
        ->push(['ok' => true, 'result' => ['message_id' => 5686]], 200);

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $job = Queue::pushed(DeliverScheduledTelegramMessage::class)->sole();
    $delivery = TelegramScheduledMessageDelivery::query()->sole();

    expect(fn () => $job->handle(app(TelegramBotService::class)))->toThrow(RequestException::class);
    expect($delivery->fresh()->toArray())->toMatchArray([
        'status' => 'retrying',
        'failure_reason' => 'telegram_http_503',
    ]);

    $job->handle(app(TelegramBotService::class));

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->telegram_message_id)->toBe(5686);
    Http::assertSentCount(2);
});

it('records a retryable error as final when the queue retry policy is exhausted', function (): void {
    makeScheduledTelegramMessage();
    Queue::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fake(['*' => Http::response(['ok' => false], 503)]);

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $job = Queue::pushed(DeliverScheduledTelegramMessage::class)->sole();
    $delivery = TelegramScheduledMessageDelivery::query()->sole();
    $failure = null;

    try {
        $job->handle(app(TelegramBotService::class));
    } catch (RequestException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(RequestException::class);
    $job->failed($failure);

    expect($delivery->fresh()->toArray())->toMatchArray([
        'status' => 'failed',
        'failure_reason' => 'telegram_http_503',
    ]);
});

it('marks a permanent Telegram 4xx failed and does not retry it', function (): void {
    makeScheduledTelegramMessage();
    Queue::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fake(['*' => Http::response(['ok' => false], 403)]);

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $job = Queue::pushed(DeliverScheduledTelegramMessage::class)->sole();
    $delivery = TelegramScheduledMessageDelivery::query()->sole();
    $job->handle(app(TelegramBotService::class));
    $job->handle(app(TelegramBotService::class));

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->failure_reason)->toBe('telegram_http_403');
    Http::assertSentCount(1);
});

it('allows the next day occurrence after a successful previous day send', function (): void {
    makeScheduledTelegramMessage(['weekdays' => [1, 2]]);
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(2)
        ->and(TelegramScheduledMessageDelivery::query()->where('status', 'pending')->count())->toBe(2);
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 2);
});

it('supports different semantic message types through the same sender and continues after a failure', function (): void {
    makeScheduledTelegramMessage(['name' => 'First']);
    makeScheduledTelegramMessage(['name' => 'Second', 'control_type' => 'reminder']);
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->where('status', 'pending')->count())->toBe(2)
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', 'reminder')->exists())->toBeTrue();
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 2);
});

it('performs no Telegram actions when no messages are due', function (): void {
    makeScheduledTelegramMessage(['send_time' => '10:00:00']);
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('uses the dedicated scheduled bot token while Academy messages keep their existing token', function (): void {
    config([
        'services.telegram.bot_token' => 'academy-test-token',
        'services.telegram.scheduled_bot_token' => 'scheduled-test-token',
    ]);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200)]);

    $bot = app(TelegramBotService::class);
    expect($bot->sendScheduledMessage('-100000000001', 'Scheduled check', '42'))->toBe(901)
        ->and($bot->sendMessage('-100000000001', 'Academy notification', '42'))->toBe(901);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botscheduled-test-token/sendMessage')
        && $request['text'] === 'Scheduled check');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botacademy-test-token/sendMessage')
        && $request['text'] === 'Academy notification');
    Http::assertSentCount(2);
});

it('fails safely and records a clear warning when the scheduled bot token is missing', function (): void {
    makeScheduledTelegramMessage(['control_type' => 'control_question']);
    Queue::fake();
    config([
        'services.telegram.bot_token' => 'academy-test-token',
        'services.telegram.scheduled_bot_token' => null,
    ]);
    Http::fake();
    Log::spy();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('queued: 1')
        ->assertExitCode(0);
    $job = Queue::pushed(DeliverScheduledTelegramMessage::class)->sole();
    $job->handle(app(TelegramBotService::class));

    expect(TelegramScheduledMessageDelivery::query()->sole())
        ->status->toBe('failed')
        ->failure_reason->toBe('scheduled_bot_token_missing');
    Log::shouldHaveReceived('warning')->once()->with('Scheduled Telegram bot token is not configured.', [
        'delivery_id' => TelegramScheduledMessageDelivery::query()->sole()->id,
    ]);
    Http::assertNothingSent();
});

it('refuses a sync queue connection so the scheduler command cannot send inline', function (): void {
    makeScheduledTelegramMessage();
    config(['queue.default' => 'sync']);
    Http::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 1; queued: 0; failed: 1; duplicate: 0')
        ->assertExitCode(1);

    expect(TelegramScheduledMessageDelivery::query()->sole()->toArray())
        ->toMatchArray([
            'status' => 'failed',
            'failure_reason' => 'queue_connection_sync',
        ]);
    Http::assertNothingSent();
});

it('catches up a missed minute only inside the configured grace window', function (): void {
    makeScheduledTelegramMessage(['send_time' => '10:59:00']);
    config(['services.telegram.scheduled_delivery_grace_minutes' => 5]);
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('queued: 1')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->sole())
        ->scheduled_for->format('H:i:s')->toBe('10:59:00')
        ->status->toBe('pending');
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 1);
});

it('does not catch up occurrences outside the grace window or from a previous day', function (): void {
    makeScheduledTelegramMessage(['name' => 'Too old', 'send_time' => '10:54:00']);
    makeScheduledTelegramMessage(['name' => 'Yesterday', 'send_time' => '10:59:00', 'weekdays' => [1]]);
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:20', 'Europe/Rome'));
    Queue::fake();

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0; queued: 0; failed: 0; duplicate: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('exposes searchable existing chat and topic selectors and persists the selected schedule fields', function (): void {
    $message = makeScheduledTelegramMessage();
    $page = new CreateTelegramScheduledMessage;
    $page->data = ['telegram_chat_record_id' => $message->telegram_chat_record_id];
    $schema = TelegramScheduledMessageResource::form(FilamentSchema::make($page)->model($message)->statePath('data'));
    $components = collect($schema->getComponents())->keyBy(fn ($component) => $component->getName());

    expect($components['telegram_chat_record_id'])->toBeInstanceOf(Select::class)
        ->and($components['telegram_chat_record_id']->isSearchable())->toBeTrue()
        ->and($components['telegram_chat_record_id']->getOptions())->toBe([
            $message->telegram_chat_record_id => 'Test work chat · -100000000001',
        ])
        ->and($components['telegram_topic_record_id'])->toBeInstanceOf(Select::class)
        ->and($components['telegram_topic_record_id']->isSearchable())->toBeTrue()
        ->and($components['telegram_topic_record_id']->getOptions())->toBe([
            $message->telegram_topic_record_id => 'Test topic · thread 42',
        ])
        ->and($components['send_time'])->toBeInstanceOf(TimePicker::class)
        ->and($components['send_time']->getLabel())->toBe('Время отправки (Europe/Rome)');

    $reloaded = TelegramScheduledMessage::query()->findOrFail($message->getKey());

    expect($reloaded->control_type)->toBe('first_cleanings_started')
        ->and($reloaded->send_time)->toStartWith('11:00')
        ->and($reloaded->weekdays)->toBe([1])
        ->and($reloaded->enabled)->toBeTrue()
        ->and($reloaded->telegramChat->title)->toBe('Test work chat')
        ->and($reloaded->telegramTopic->telegram_thread_id)->toBe('42');
});

it('offers enabled known chats and non-apartment topics, scoped strictly to the selected chat', function (): void {
    $firstChat = TelegramChat::query()->create([
        'telegram_chat_id' => '-100000000101', 'title' => 'First forum', 'type' => 'supergroup', 'is_enabled' => true,
    ]);
    $secondChat = TelegramChat::query()->create([
        'telegram_chat_id' => '-100000000202', 'title' => 'Second forum', 'type' => 'supergroup', 'is_enabled' => true,
    ]);
    $disabledChat = TelegramChat::query()->create([
        'telegram_chat_id' => '-100000000303', 'title' => 'Disabled forum', 'type' => 'supergroup', 'is_enabled' => false,
    ]);
    $apartmentless = TelegramTopic::query()->create([
        'telegram_chat_id' => $firstChat->getKey(), 'telegram_thread_id' => '381534', 'title' => 'Duty desk', 'apartment_id' => null, 'is_enabled' => true,
    ]);
    TelegramTopic::query()->create([
        'telegram_chat_id' => $secondChat->getKey(), 'telegram_thread_id' => '71', 'title' => 'Different forum topic', 'is_enabled' => true,
    ]);
    TelegramTopic::query()->create([
        'telegram_chat_id' => $firstChat->getKey(), 'telegram_thread_id' => '72', 'title' => 'Disabled topic', 'is_enabled' => false,
    ]);

    $catalog = app(TelegramDestinationCatalog::class);
    $chats = $catalog->chatOptions();
    $topics = $catalog->topicOptions((int) $firstChat->getKey());
    $otherChatTopic = TelegramTopic::query()->where('telegram_chat_id', $secondChat->getKey())->firstOrFail();

    expect($chats)->toHaveKey($firstChat->getKey())
        ->and($chats)->toHaveKey($secondChat->getKey())
        ->and($chats)->not->toHaveKey($disabledChat->getKey())
        ->and($chats[$firstChat->getKey()])->toContain('First forum', '-100000000101')
        ->and($topics)->toBe([$apartmentless->getKey() => 'Duty desk · thread 381534'])
        ->and($topics)->not->toHaveKey($otherChatTopic->getKey());
});

it('preserves a disabled current destination only while editing its schedule', function (): void {
    $message = makeScheduledTelegramMessage();
    $chat = $message->telegramChat;
    $topic = $message->telegramTopic;
    $chat->update(['is_enabled' => false]);
    $topic->update(['is_enabled' => false]);

    $catalog = app(TelegramDestinationCatalog::class);

    expect($catalog->chatOptions())->not->toHaveKey($chat->getKey())
        ->and($catalog->chatOptions((int) $chat->getKey()))->toHaveKey($chat->getKey())
        ->and($catalog->topicOptions((int) $chat->getKey()))->toBe([])
        ->and($catalog->topicOptions((int) $chat->getKey(), (int) $topic->getKey()))
        ->toBe([$topic->getKey() => 'Test topic · thread 42 · отключена (текущий адресат)']);
});

it('keeps the sender every minute and schedules the scheduled poll every five minutes without scheduling a queue worker', function (): void {
    $events = collect(app(Schedule::class)->events());
    $senderPosition = $events->search(fn ($event): bool => str_contains($event->command ?? '', 'telegram:scheduled-messages-send'));
    $poll = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'telegram:scheduled-poll'));
    $event = $senderPosition === false ? null : $events->get($senderPosition);
    $worker = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'queue:work'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($poll)->not->toBeNull()
        ->and($poll->expression)->toBe('*/5 * * * *')
        ->and($poll->withoutOverlapping)->toBeTrue()
        ->and($worker)->toBeNull();
});

it('validates the existing control form follow-up settings and their defaults', function (): void {
    $message = makeScheduledTelegramMessage();
    $page = new CreateTelegramScheduledMessage;
    $page->data = ['telegram_chat_record_id' => $message->telegram_chat_record_id];
    $schema = TelegramScheduledMessageResource::form(FilamentSchema::make($page)->model($message)->statePath('data'));
    $components = collect($schema->getComponents())->keyBy(fn ($component) => $component->getName());

    expect($components['followup_repeat_enabled']->getDefaultState())->toBeFalse()
        ->and($components['followup_interval_minutes']->getDefaultState())->toBe(60)
        ->and($components['followup_max_repeats']->getDefaultState())->toBe(2)
        ->and($components['followup_reminder_enabled']->getDefaultState())->toBeFalse()
        ->and($components['followup_reminder_minutes']->getDefaultState())->toBe(20);
    foreach ([
        'followup_interval_minutes' => [0, 60],
        'followup_max_repeats' => [3, 2],
        'followup_reminder_minutes' => [0, 20],
    ] as $field => [$invalid, $valid]) {
        $rules = $components[$field]->getValidationRules();
        expect(Validator::make(['value' => $invalid], ['value' => $rules])->fails())->toBeTrue()
            ->and(Validator::make(['value' => $valid], ['value' => $rules])->passes())->toBeTrue();
    }
});

it('queues the new OKS posts independently of the legacy closing controls in the same topic', function (): void {
    config(['services.telegram.oks_current_controls_enabled' => true]);
    makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    makeScheduledTelegramMessage(['control_type' => 'first_cleanings_started', 'send_time' => '11:00:00']);
    Queue::fake();

    foreach (['09:30', '10:15', '11:00', '11:05', '12:00', '12:30', '14:00', '16:00', '17:30', '18:00'] as $time) {
        Carbon::setTestNow(Carbon::parse('2026-09-28 '.$time.':20', 'Europe/Rome'));
        $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
        $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    }

    $deliveries = TelegramScheduledMessageDelivery::query()->orderBy('scheduled_for')->get();
    expect($deliveries)->toHaveCount(10)
        ->and($deliveries->pluck('control_type')->all())->toBe([
            'oks_schedule', 'oks_start_ten', 'oks_start_eleven', 'oks_linen', 'oks_first_progress',
            'oks_first_finish', 'oks_guest_arrivals', 'oks_couriers', 'oks_cleanings', 'oks_payments',
        ]);
    Queue::assertPushed(DeliverScheduledTelegramMessage::class, 10);
});

it('keeps the two OKS questions independent and saves a reason in the original public card', function (): void {
    (require database_path('migrations/2026_10_03_000000_allow_replyless_scheduled_control_responses.php'))->up();
    (require database_path('migrations/2026_10_14_000000_support_scheduled_control_button_responses.php'))->up();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });
    $duty = new User;
    $duty->forceFill(['name' => 'Дежурный', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '102'])->save();
    $other = $duty->replicate();
    $other->forceFill(['telegram_id' => '103'])->save();
    $anchor = makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    $delivery = $anchor->deliveries()->create([
        'control_type' => 'oks_start_eleven', 'bot_type' => 'work', 'status' => 'sent', 'scheduled_for' => now()->startOfMinute(),
        'sent_at' => now(), 'telegram_message_id' => 321, 'chat_id' => '-100000000001', 'message_thread_id' => '42',
        'sequence_context' => ['oks_assignments' => ['navigli' => $duty->id]],
    ]);
    config(['services.telegram.bot_token' => 'test-work', 'services.telegram.bot_username' => 'test_work_bot', 'services.telegram.work_webhook_secret' => 'test-oks-card']);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $initData = function (int $id) use ($delivery): string {
        $values = ['auth_date' => now()->timestamp, 'start_param' => 'oks_'.$delivery->id.'_start_navigli', 'user' => json_encode(['id' => $id])];
        $secret = hash_hmac('sha256', 'test-work', 'WebAppData', true);
        $check = collect($values)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");

        return http_build_query($values + ['hash' => hash_hmac('sha256', $check, $secret)]);
    };
    $this->postJson(route('telegram.auth'), ['init_data' => $initData(999)])->assertForbidden();
    $this->postJson(route('telegram.auth'), ['init_data' => $initData(103)])->assertForbidden();
    expect(User::query()->count())->toBe(2);
    $this->postJson(route('telegram.auth'), ['init_data' => $initData(102)])->assertOk()
        ->assertJsonPath('redirect', route('oks.response.show', [$delivery, 'start', 'navigli']));
    $this->get(route('oks.response.show', [$delivery, 'start', 'navigli']))->assertOk()->assertSee('Какие уборки не начались');
    $this->actingAs($other)->post(route('oks.response.save', [$delivery, 'start', 'navigli']), ['note' => 'Чужой ответ'])->assertForbidden();
    $this->actingAs($duty)->post(route('oks.response.save', [$delivery, 'start', 'navigli']), ['note' => 'Via Vigevano · ждём ключи', 'version' => 0])->assertOk();
    $callback = ['callback_query' => [
        'id' => 'oks-test-positive', 'from' => ['id' => 102, 'is_bot' => false], 'data' => 'oksc:'.$delivery->id.':late:navigli:yes',
        'message' => ['message_id' => 321, 'message_thread_id' => 42, 'chat' => ['id' => -100000000001, 'type' => 'supergroup']],
    ]];
    $this->postJson('/telegram/work-webhook/test-oks-card', $callback)->assertOk();
    $this->postJson('/telegram/work-webhook/test-oks-card', $callback)->assertOk();

    expect($delivery->responses()->count())->toBe(2)
        ->and(app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery)['navigli']['telegram:102']['classification'])->toBe('problem');
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-09-28');
    $control = collect($summary['controls'])->firstWhere('control_type', 'oks_start_eleven');
    expect($control['status'])->toBe('problem')
        ->and($control['district_results'][0]['conflict'])->toBeFalse()
        ->and($control['district_results'][0]['details'])->toContain('Via Vigevano · ждём ключи');
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText') && $request['message_id'] === 321
        && str_contains($request['text'], 'ждём ключи') && str_contains($request['text'], 'Освободили') && ! str_contains($request['text'], 'Como'));
});

it('sends one reply reminder for unanswered OKS districts', function (): void {
    $anchor = makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00', 'followup_reminder_enabled' => true]);
    $delivery = $anchor->deliveries()->create([
        'control_type' => 'oks_cleanings', 'bot_type' => 'work', 'status' => 'sent', 'scheduled_for' => now()->subMinutes(21),
        'sent_at' => now()->subMinutes(21), 'telegram_message_id' => 321, 'chat_id' => '-100000000001', 'message_thread_id' => '42',
        'sequence_context' => ['oks_assignments' => ['navigli' => 11, 'lodi' => 12]],
    ]);
    config(['services.telegram.bot_token' => 'test-work']);
    $followups = app(TelegramScheduledControlFollowupService::class);
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 322]])]);
    $followups->queueDue();
    $followups->queueDue();
    $followup = $delivery->followups()->sole();
    Queue::assertPushed(DeliverScheduledControlFollowup::class, 1);
    (new DeliverScheduledControlFollowup($followup->id))->handle(app(TelegramBotService::class), $followups);
    expect($followup->refresh()->status)->toBe('sent');
    Http::assertSentCount(1);
    $request = Http::recorded()->sole()[0];
    expect($request['reply_to_message_id'])->toBe(321)
        ->and($request['text'])->toContain('Navigli', 'Lodi')->not->toContain('Como');
});

it('starts the new OKS shift with individual district answers and only sends controls to active districts', function (): void {
    (require database_path('migrations/2026_10_03_000000_allow_replyless_scheduled_control_responses.php'))->up();
    (require database_path('migrations/2026_10_14_000000_support_scheduled_control_button_responses.php'))->up();
    (require database_path('migrations/2026_11_15_000000_create_shift_duties_table.php'))->up();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });
    $supervisor = DB::table('users')->insertGetId(['name' => 'Дежурный', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '102']);
    DB::table('users')->insert(['name' => 'Клинер', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '103']);
    config(['services.telegram.oks_current_controls_enabled' => true, 'services.telegram.bot_token' => 'test-work',
        'services.telegram.bot_username' => 'test_work_bot', 'services.telegram.work_webhook_secret' => 'test-oks-opening']);
    makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    Carbon::setTestNow(Carbon::parse('2026-09-28 09:00:20', 'Europe/Rome'));
    Queue::fake();
    Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => [
        'message_id' => str_contains((string) ($request['rich_message']['markdown'] ?? $request['text'] ?? ''), 'Открытие смены') ? 123 : 124,
    ]]));
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $opening = TelegramScheduledMessageDelivery::sole();
    (new DeliverScheduledTelegramMessage($opening->id, now()->toImmutable()->addHour()))->handle(app(TelegramBotService::class));
    expect($opening->refresh()->status)->toBe('sent');

    foreach ([['lodi', 'yes', 103], ['navigli', 'yes', 102], ['como', 'no', 102], ['navigli', 'no', 102]] as $i => [$district, $choice, $actor]) {
        $this->postJson('/telegram/work-webhook/test-oks-opening', ['callback_query' => [
            'id' => 'oks-quick-'.$i, 'from' => ['id' => $actor, 'is_bot' => false], 'data' => 'oks:'.$opening->id.':quick:'.$district.':'.$choice,
            'message' => ['message_id' => 123, 'message_thread_id' => 42, 'chat' => ['id' => -100000000001, 'type' => 'supergroup']],
        ]])->assertOk();
    }
    expect($opening->refresh()->sequence_context['opening_draft'])->toBe(['navigli' => $supervisor, 'como' => 0])
        ->and(ShiftDuty::sole()->user_id)->toBe($supervisor);

    Carbon::setTestNow(Carbon::parse('2026-09-28 09:30:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $control = TelegramScheduledMessageDelivery::query()->where('control_type', 'oks_schedule')->sole();
    (new DeliverScheduledTelegramMessage($control->id, now()->toImmutable()->addHour()))->handle(app(TelegramBotService::class));
    expect($control->refresh()->sequence_context['oks_assignments'])->toBe(['navigli' => $supervisor]);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendRichMessage')
        && str_contains((string) ($request['rich_message']['markdown'] ?? ''), 'Время уборок и заметки')
        && str_contains($request['rich_message']['markdown'], 'Navigli') && ! str_contains($request['rich_message']['markdown'], 'Como')
        && str_contains((string) ($request['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), 'startapp=oks_card_'.$control->id));
});

it('runs interactive OKS cards in the requested topic without altering the work shift or summary', function (): void {
    (require database_path('migrations/2026_10_03_000000_allow_replyless_scheduled_control_responses.php'))->up();
    (require database_path('migrations/2026_10_14_000000_support_scheduled_control_button_responses.php'))->up();
    (require database_path('migrations/2026_11_15_000000_create_shift_duties_table.php'))->up();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });
    DB::table('users')->insert([
        ['name' => 'Дежурный', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '102'],
        ['name' => 'Второй дежурный', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true, 'telegram_id' => '103'],
    ]);
    config(['services.telegram.bot_token' => 'test-work', 'services.telegram.bot_username' => 'test_work_bot',
        'services.telegram.work_webhook_secret' => 'test-oks-live', 'services.telegram.oks_current_controls_enabled' => false]);
    $anchor = makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    $sent = 123;
    Http::fake(function ($request) use (&$sent) {
        if (str_ends_with($request->url(), '/sendRichMessage') && str_contains($request['rich_message']['markdown'], 'Бельё в приоритетных квартирах')) {
            return Http::response(['ok' => false, 'description' => 'Bad Request: rich messages are disabled'], 400);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => $sent++]]);
    });
    $args = ['--template' => $anchor->id, '--chat' => '-100000000001', '--thread' => '134'];
    $this->artisan('telegram:oks-test', ['stage' => 'cards'] + $args)->assertExitCode(1);
    $this->artisan('telegram:oks-test', ['stage' => 'opening'] + $args)->assertSuccessful();
    $opening = TelegramScheduledMessageDelivery::query()->withOksTests()->sole();
    expect($opening->message_thread_id)->toBe('134');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendRichMessage')
        && str_contains($request['rich_message']['markdown'], 'Открытие смены')
        && count($request['reply_markup']['inline_keyboard'][0]) === 1);

    $initData = function (TelegramScheduledMessageDelivery $delivery, int $id): string {
        $fields = ['auth_date' => now()->timestamp, 'start_param' => 'oks_card_'.$delivery->id, 'user' => json_encode(['id' => $id])];
        $key = hash_hmac('sha256', 'test-work', 'WebAppData', true);
        $data = collect($fields)->map(fn ($value, $name): string => $name.'='.$value)->implode("\n");

        return http_build_query($fields + ['hash' => hash_hmac('sha256', $data, $key)]);
    };
    $first = User::query()->where('telegram_id', 102)->firstOrFail();
    ShiftDuty::query()->create(['date' => '2026-09-28', 'district_key' => 'lodi', 'user_id' => $first->id]);
    $this->postJson(route('telegram.auth'), ['init_data' => $initData($opening, 102)])->assertOk()
        ->assertJsonPath('redirect', route('oks.card.show', $opening));
    $this->get(route('oks.card.show', $opening))->assertOk()->assertSee('Certosa');
    foreach (['lodi' => 'no', 'certosa' => 'yes', 'navigli' => 'yes'] as $district => $choice) {
        $this->post(route('oks.card.save', [$opening, $district]), ['answers' => ['opening' => ['choice' => $choice]]])->assertOk();
    }
    $this->postJson(route('telegram.auth'), ['init_data' => $initData($opening, 103)])->assertOk();
    $this->post(route('oks.card.save', [$opening, 'como']), ['answers' => ['opening' => ['choice' => 'yes']]])->assertOk();
    expect(ShiftDuty::query()->sole()->district_key)->toBe('lodi');
    $this->artisan('telegram:oks-test', ['stage' => 'cards'] + $args)->assertSuccessful();
    $this->artisan('telegram:oks-test', ['stage' => 'cards'] + $args)->assertSuccessful();
    expect(TelegramScheduledMessageDelivery::query()->withOksTests()->count())->toBe(11)
        ->and(TelegramScheduledMessageDelivery::query()->count())->toBe(0);
    $posts = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendRichMessage'));
    expect($posts)->toHaveCount(11);
    foreach ($posts as [$request]) {
        expect($request['message_thread_id'])->toBe(134)
            ->and(collect($request['reply_markup']['inline_keyboard'])->collapse())->toHaveCount(1)
            ->and($request['rich_message']['markdown'])->not->toContain('EXTRA TIME');
    }
    $fallback = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
    expect($fallback['message_thread_id'])->toBe(134)
        ->and($fallback['text'])->toContain('Бельё в приоритетных квартирах')
        ->and($fallback['reply_markup']['inline_keyboard'][0][0]['text'])->toBe('Открыть контроль');

    $card = TelegramScheduledMessageDelivery::query()->withOksTests()->where('control_type', 'oks_start_eleven')->sole();
    $this->get(route('oks.card.show', $card))->assertRedirect(route('oks.card.show', [$card, 'como']));
    $this->get(route('oks.card.show', [$card, 'certosa']))->assertForbidden();
    $this->postJson(route('telegram.auth'), ['init_data' => $initData($card, 102)])->assertOk()
        ->assertJsonPath('redirect', route('oks.card.show', $card));
    $this->get(route('oks.card.show', $card))->assertOk()->assertSee('Certosa')->assertSee('Navigli')->assertDontSee('Como');
    $this->get(route('oks.card.show', [$card, 'certosa']))->assertOk()->assertSee('EXTRA TIME');
    $answers = ['start' => ['choice' => 'yes', 'version' => 0], 'late' => ['choice' => 'note', 'version' => 0]];
    $saveRoute = route('oks.card.save', [$card, 'certosa']);
    $this->postJson($saveRoute, ['answers' => $answers])->assertUnprocessable()->assertJsonValidationErrors('answers.late.note');
    expect($card->responses()->count())->toBe(0);
    $answers['late']['note'] = 'Тестовая причина';
    $this->post($saveRoute, ['answers' => $answers])->assertOk()->assertSee('Ответ сохранён.');
    $this->post($saveRoute, ['answers' => $answers])->assertOk();
    expect($card->responses()->pluck('classification', 'classification_reason')->all())->toBe(['oks_card:start' => 'confirmed', 'oks_card:late' => 'problem'])
        ->and($card->responses()->where('classification', 'problem')->sole()->text)->toBe('Тестовая причина');
    $answers['start'] = ['choice' => 'note', 'version' => $card->responses()->where('classification', 'confirmed')->sole()->id, 'note' => 'Корректировка'];
    $answers['late']['note'] = 'Ответ из устаревшей формы';
    $this->post($saveRoute, ['answers' => $answers])->assertConflict();
    expect($card->responses()->count())->toBe(2);
    expect(app(TelegramScheduledControlSummaryBuilder::class)->build('2026-09-28')['deliveries'] ?? [])->toBe([]);
    $edits = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/editMessageText') && $request['message_id'] === $card->telegram_message_id);
    expect($edits)->toHaveCount(1)
        ->and($edits->sole()[0]['rich_message']['markdown'])->toContain('ТЕСТ ОКС', 'Гости ещё в квартире')->not->toContain('Тестовая причина');
});

it('does not send an OKS card again when Telegram delivery is uncertain', function (): void {
    $anchor = makeScheduledTelegramMessage(['control_type' => 'schedule_checked', 'send_time' => '09:30:00']);
    $delivery = $anchor->deliveries()->create([
        'control_type' => 'oks_linen', 'bot_type' => 'work', 'status' => 'retrying', 'scheduled_for' => now(),
        'chat_id' => '-100000000001', 'message_thread_id' => '42', 'sequence_context' => ['oks_assignments' => ['navigli' => 11]],
    ]);
    config(['services.telegram.bot_token' => 'test-work', 'services.telegram.bot_username' => 'test_work_bot']);
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;
        throw new ConnectionException('No confirmation from Telegram');
    });
    $job = new DeliverScheduledTelegramMessage($delivery->id, now()->toImmutable()->addHour());
    $job->handle(app(TelegramBotService::class));
    $job->handle(app(TelegramBotService::class));
    expect($delivery->fresh()->status)->toBe('uncertain')->and($attempts)->toBe(1);
});

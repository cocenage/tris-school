<?php

use App\Filament\Resources\TelegramScheduledMessages\Pages\CreateTelegramScheduledMessage;
use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use App\Jobs\DeliverScheduledTelegramMessage;
use App\Models\TelegramChat;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDestinationCatalog;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
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
use Mockery\MockInterface;

beforeEach(function (): void {
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

it('does not attempt the same scheduled occurrence twice', function (): void {
    makeScheduledTelegramMessage(['send_time' => '10:59:00']);
    Queue::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    Http::fake(['*' => Http::response(['result' => ['message_id' => 5680]], 200)]);

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
    Http::fake(['*' => Http::response(['result' => ['message_id' => 901]], 200)]);

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
    makeScheduledTelegramMessage();
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
        ]);

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

it('registers the sender and short-lived queue worker on Laravel scheduler at minute frequency', function (): void {
    $events = collect(app(Schedule::class)->events());
    $senderPosition = $events->search(fn ($event): bool => str_contains($event->command ?? '', 'telegram:scheduled-messages-send'));
    $workerPosition = $events->search(fn ($event): bool => str_contains($event->command ?? '', 'queue:work database'));
    $event = $senderPosition === false ? null : $events->get($senderPosition);
    $worker = $workerPosition === false ? null : $events->get($workerPosition);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($worker)->not->toBeNull()
        ->and($worker->command)->toContain('--queue=default --stop-when-empty --tries=8 --timeout=30 --max-time=50')
        ->and($worker->expression)->toBe('* * * * *')
        ->and($worker->withoutOverlapping)->toBeTrue()
        ->and($workerPosition)->toBeGreaterThan($senderPosition);
});

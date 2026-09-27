<?php

use App\Filament\Resources\TelegramScheduledMessages\TelegramScheduledMessageResource;
use App\Models\TelegramChat;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function (): void {
    config([
        'app.timezone' => 'Europe/Rome',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.analytics.database' => ':memory:',
    ]);

    DB::purge('sqlite');
    DB::purge('analytics');

    $migration = require base_path('database/migrations/2026_09_27_000000_create_telegram_scheduled_messages_tables.php');
    $migration->up();

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

it('sends an active due message to its configured chat and thread and stores the returned telegram message id', function (): void {
    makeScheduledTelegramMessage();
    $this->mock(TelegramBotService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendMessage')->once()
            ->with('-100000000001', '🔴 Проверка уборок', '42')
            ->andReturn(5678);
    });

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->sole()->toArray())
        ->toMatchArray([
            'control_type' => 'first_cleanings_started',
            'chat_id' => '-100000000001',
            'message_thread_id' => '42',
            'telegram_message_id' => 5678,
            'status' => 'sent',
        ])
        ->and(TelegramScheduledMessageDelivery::query()->sole()->sent_at)->not->toBeNull();
});

it('does not send a future, disabled, or wrong-weekday message', function (): void {
    makeScheduledTelegramMessage(['name' => 'Future', 'send_time' => '12:00:00']);
    makeScheduledTelegramMessage(['name' => 'Disabled', 'enabled' => false]);
    makeScheduledTelegramMessage(['name' => 'Wrong weekday', 'weekdays' => [2]]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Telegram actions: 0')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(0);
});

it('respects configured weekdays when the message is due at the current minute', function (): void {
    makeScheduledTelegramMessage(['weekdays' => [2]]);
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:20', 'Europe/Rome'));
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')->once()->andReturn(5679));

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->sole()->status)->toBe('sent');
});

it('does not attempt the same scheduled occurrence twice', function (): void {
    makeScheduledTelegramMessage();
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')->once()->andReturn(5680));

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('duplicate: 1')
        ->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(1);
});

it('allows the next day occurrence after a successful previous day send', function (): void {
    makeScheduledTelegramMessage(['weekdays' => [1, 2]]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')->twice()->andReturn(5681, 5682));

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);
    Carbon::setTestNow(Carbon::parse('2026-09-29 11:00:20', 'Europe/Rome'));
    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(0);

    expect(TelegramScheduledMessageDelivery::query()->count())->toBe(2)
        ->and(TelegramScheduledMessageDelivery::query()->where('status', 'sent')->count())->toBe(2);
});

it('continues to other due messages when one Telegram send fails', function (): void {
    makeScheduledTelegramMessage(['name' => 'First']);
    makeScheduledTelegramMessage(['name' => 'Second', 'control_type' => 'couriers_completed']);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendMessage')->twice()->andReturn(null, 5683));

    $this->artisan('telegram:scheduled-messages-send')->assertExitCode(1);

    expect(TelegramScheduledMessageDelivery::query()->where('status', 'failed')->count())->toBe(1)
        ->and(TelegramScheduledMessageDelivery::query()->where('status', 'sent')->count())->toBe(1);
});

it('performs no Telegram actions when no messages are due', function (): void {
    makeScheduledTelegramMessage(['send_time' => '10:00:00']);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    $this->artisan('telegram:scheduled-messages-send')
        ->expectsOutputToContain('Due: 0')
        ->expectsOutputToContain('Telegram actions: 0')
        ->assertExitCode(0);
});

it('exposes searchable existing chat and topic selectors and persists the selected schedule fields', function (): void {
    $message = makeScheduledTelegramMessage();
    $schema = TelegramScheduledMessageResource::form(FilamentSchema::make());
    $components = collect($schema->getComponents())->keyBy(fn ($component) => $component->getName());

    expect($components['telegram_chat_record_id'])->toBeInstanceOf(Select::class)
        ->and($components['telegram_chat_record_id']->isSearchable())->toBeTrue()
        ->and($components['telegram_chat_record_id']->getOptions())->toBe([$message->telegram_chat_record_id => 'Test work chat'])
        ->and($components['telegram_topic_record_id'])->toBeInstanceOf(Select::class)
        ->and($components['telegram_topic_record_id']->isSearchable())->toBeTrue();

    $reloaded = TelegramScheduledMessage::query()->findOrFail($message->getKey());

    expect($reloaded->control_type)->toBe('first_cleanings_started')
        ->and($reloaded->send_time)->toStartWith('11:00')
        ->and($reloaded->weekdays)->toBe([1])
        ->and($reloaded->enabled)->toBeTrue()
        ->and($reloaded->telegramChat->title)->toBe('Test work chat')
        ->and($reloaded->telegramTopic->telegram_thread_id)->toBe('42');
});

it('registers the sender on Laravel scheduler at minute frequency', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'telegram:scheduled-messages-send'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

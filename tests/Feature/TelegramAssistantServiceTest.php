<?php

use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramAssistantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'database.connections.analytics.database' => ':memory:',
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.assistant_enabled' => true,
        'services.telegram.assistant_staff_chat_id' => '-100staff',
        'services.telegram.assistant_staff_thread_id' => null,
    ]);

    DB::purge('analytics');

    $schema = Schema::connection('analytics');

    $schema->create('telegram_chats', function (Blueprint $table) {
        $table->id();
        $table->string('telegram_chat_id')->unique();
        $table->string('title')->nullable();
        $table->string('type')->nullable();
        $table->boolean('is_enabled')->default(true);
        $table->timestamps();
    });

    $schema->create('telegram_topics', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('telegram_chat_id');
        $table->string('telegram_thread_id');
        $table->string('title')->nullable();
        $table->string('purpose')->nullable();
        $table->boolean('is_enabled')->default(true);
        $table->timestamps();
    });

    $schema->create('telegram_users', function (Blueprint $table) {
        $table->id();
        $table->string('telegram_user_id')->unique();
        $table->string('username')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('full_name')->nullable();
        $table->unsignedBigInteger('linked_user_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
    });

    $schema->create('telegram_messages', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('telegram_chat_id');
        $table->unsignedBigInteger('telegram_topic_id')->nullable();
        $table->unsignedBigInteger('telegram_user_id')->nullable();
        $table->string('message_id');
        $table->string('message_type')->default('text');
        $table->text('text')->nullable();
        $table->text('caption')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('edited_at')->nullable();
        $table->json('raw')->nullable();
        $table->timestamps();
    });

    $schema->create('telegram_assistant_requests', function (Blueprint $table) {
        $table->id();
        $table->string('telegram_chat_id');
        $table->string('telegram_topic_id')->nullable();
        $table->string('telegram_user_id')->nullable();
        $table->unsignedBigInteger('linked_user_id')->nullable();
        $table->unsignedBigInteger('root_message_id');
        $table->string('last_bot_message_id')->nullable();
        $table->string('category', 40)->default('other');
        $table->string('status', 40)->default('received');
        $table->text('original_text')->nullable();
        $table->boolean('is_sensitive')->default(false);
        $table->json('metadata')->nullable();
        $table->timestamps();
    });

    $schema->create('telegram_assistant_request_messages', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('request_id');
        $table->unsignedBigInteger('telegram_message_id');
        $table->string('direction', 20)->default('incoming');
        $table->timestamps();
    });
});

afterEach(function () {
    DB::purge('analytics');
});

function assistantTestMessage(string $text, int $messageId = 10): array
{
    $chat = TelegramChat::create([
        'telegram_chat_id' => '-100123',
        'title' => 'Test chat',
        'type' => 'supergroup',
        'is_enabled' => true,
    ]);

    $user = TelegramUser::create([
        'telegram_user_id' => '777',
        'full_name' => 'Test user',
        'linked_user_id' => 1,
    ]);

    $model = TelegramMessage::create([
        'telegram_chat_id' => $chat->id,
        'telegram_user_id' => $user->id,
        'message_id' => (string) $messageId,
        'message_type' => 'text',
        'text' => $text,
        'sent_at' => now(),
    ]);

    return [
        'model' => $model->fresh(['chat', 'topic', 'telegramUser']),
        'payload' => [
            'message_id' => $messageId,
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'is_bot' => false],
            'text' => $text,
        ],
    ];
}

it('stores an activated Telegram request and does not duplicate retries', function () {
    Http::fake(fn () => Http::response([
        'ok' => true,
        'result' => ['message_id' => 900],
    ]));

    $data = assistantTestMessage('Завтра не смогу выйти на смену');
    $service = app(TelegramAssistantService::class);

    $first = $service->handle($data['model'], $data['payload']);
    $second = $service->handle($data['model'], $data['payload']);

    expect($first?->id)->toBe($second?->id)
        ->and(DB::connection('analytics')->table('telegram_assistant_requests')->count())->toBe(1)
        ->and(DB::connection('analytics')->table('telegram_assistant_request_messages')->count())->toBe(1)
        ->and(Http::recorded())->toHaveCount(2);
});

it('ignores ordinary group messages without creating an assistant request', function () {
    Http::fake();

    $data = assistantTestMessage('Всем привет, хорошего дня');
    $result = app(TelegramAssistantService::class)->handle($data['model'], $data['payload']);

    expect($result)->toBeNull()
        ->and(DB::connection('analytics')->table('telegram_assistant_requests')->count())->toBe(0)
        ->and(Http::recorded())->toHaveCount(0);
});

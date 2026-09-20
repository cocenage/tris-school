<?php

namespace Tests\Support;

use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TelegramOperationalTestDatabase
{
    public static function refresh(): void
    {
        config([
            'app.timezone' => 'Europe/Rome',
            'database.connections.analytics.database' => ':memory:',
            'services.telegram.work_allowed_chat_ids' => [],
            'services.telegram.operational_observer_enabled' => false,
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
            $table->boolean('is_bot')->default(false);
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
            $table->unique(['telegram_chat_id', 'message_id']);
        });

        $schema->create('telegram_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_message_id');
            $table->string('type');
            $table->string('file_id');
            $table->string('file_unique_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
        });

        $migration = require base_path('database/migrations/2026_09_16_000000_create_telegram_operational_event_ledger.php');
        $migration->up();

        $apartmentContextMigration = require base_path('database/migrations/2026_09_20_000000_add_apartment_context_to_telegram_operational_events.php');
        $apartmentContextMigration->up();
    }

    public static function message(
        string $text,
        string $sentAt = '2026-06-17 08:00:00',
        string $messageId = '1',
        array $raw = [],
        string $chatId = '-1001',
        string $chatType = 'supergroup',
        ?string $threadId = '11',
        string $userId = '101',
        string $messageType = 'text',
    ): TelegramMessage {
        $chat = TelegramChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['title' => 'Work chat', 'type' => $chatType, 'is_enabled' => true],
        );

        $topic = $threadId === null ? null : TelegramTopic::firstOrCreate(
            ['telegram_chat_id' => $chat->id, 'telegram_thread_id' => $threadId],
            ['title' => 'Operations', 'is_enabled' => true],
        );

        $user = TelegramUser::firstOrCreate(
            ['telegram_user_id' => $userId],
            ['full_name' => 'Worker '.$userId],
        );

        $payload = array_replace_recursive([
            'message' => [
                'message_id' => (int) $messageId,
                'date' => Carbon::parse($sentAt, 'Europe/Rome')->timestamp,
                'chat' => ['id' => (int) $chatId, 'type' => $chatType],
                'from' => ['id' => (int) $userId, 'is_bot' => false],
                'text' => $text,
            ],
        ], $raw);

        return TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'telegram_topic_id' => $topic?->id,
            'telegram_user_id' => $user->id,
            'message_id' => $messageId,
            'message_type' => $messageType,
            'text' => $text !== '' ? $text : null,
            'sent_at' => Carbon::parse($sentAt, 'Europe/Rome'),
            'raw' => $payload,
        ])->fresh(['chat', 'topic', 'telegramUser', 'attachments']);
    }

    public static function purge(): void
    {
        DB::purge('analytics');
    }
}

<?php

use App\Services\Telegram\TelegramForumDigestBuilder;
use App\Services\Telegram\TelegramDigestFormatter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'app.timezone' => 'Europe/Rome',
        'database.connections.analytics.database' => ':memory:',
        'services.telegram.digest_target_chat_id' => null,
        'services.telegram.digest_target_thread_id' => null,
    ]);

    DB::purge('analytics');
    $schema = Schema::connection('analytics');

    $schema->create('telegram_chats', function (Blueprint $table) {
        $table->id();
        $table->string('telegram_chat_id')->nullable();
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
        $table->json('raw')->nullable();
        $table->timestamps();
    });

    $schema->create('telegram_attachments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('telegram_message_id');
        $table->string('type');
        $table->string('file_id');
        $table->timestamps();
    });

    $analytics = DB::connection('analytics');
    $forum = $analytics->table('telegram_chats')->insertGetId([
        'telegram_chat_id' => '-1001', 'title' => 'Основной форум', 'type' => 'supergroup', 'is_enabled' => true,
    ]);
    $secondForum = $analytics->table('telegram_chats')->insertGetId([
        'telegram_chat_id' => '-1002', 'title' => 'Второй форум', 'type' => 'supergroup', 'is_enabled' => true,
    ]);
    $private = $analytics->table('telegram_chats')->insertGetId([
        'telegram_chat_id' => '9001', 'title' => 'Личный чат', 'type' => 'private', 'is_enabled' => true,
    ]);
    $topicA = $analytics->table('telegram_topics')->insertGetId([
        'telegram_chat_id' => $forum, 'telegram_thread_id' => '11', 'title' => 'Смена',
    ]);
    $topicB = $analytics->table('telegram_topics')->insertGetId([
        'telegram_chat_id' => $forum, 'telegram_thread_id' => '12', 'title' => 'Ключи',
    ]);
    $topicC = $analytics->table('telegram_topics')->insertGetId([
        'telegram_chat_id' => $secondForum, 'telegram_thread_id' => '21', 'title' => 'Общее',
    ]);
    $insertMessage = function (int $chat, ?int $topic, string $id, string $text, string $time, int $author = 1) use ($analytics) {
        return $analytics->table('telegram_messages')->insertGetId([
            'telegram_chat_id' => $chat,
            'telegram_topic_id' => $topic,
            'telegram_user_id' => $author,
            'message_id' => $id,
            'message_type' => 'text',
            'text' => $text,
            'sent_at' => $time,
        ]);
    };

    $messageA = $insertMessage($forum, $topicA, '1', 'Не работает замок, кто поможет?', '2026-06-17 08:00:00');
    $insertMessage($forum, $topicA, '2', 'Проверили, вопрос решено', '2026-06-17 09:00:00', 2);
    $insertMessage($forum, $topicB, '3', 'Нет ключей, не могу начать', '2026-06-17 10:00:00', 3);
    $insertMessage($forum, $topicB, '4', 'Опять не работает', '2026-06-17 11:00:00', 3);
    $insertMessage($secondForum, $topicC, '5', 'Спасибо, отлично помогли', '2026-06-17 12:00:00', 4);
    $insertMessage($private, $topicC, '6', 'Не работает в личном чате', '2026-06-17 12:00:00', 5);
    $insertMessage($forum, null, '7', 'Не работает сообщение без темы', '2026-06-17 13:00:00', 6);
    // Same external message key in one forum: it must not be counted twice.
    $insertMessage($forum, $topicB, '4', 'Опять не работает', '2026-06-17 11:00:00', 3);

    $analytics->table('telegram_attachments')->insert([
        'telegram_message_id' => $messageA, 'type' => 'photo', 'file_id' => 'file-1',
    ]);
});

afterEach(function () {
    DB::purge('analytics');
});

it('includes only supergroup topics, keeps forums and topics separate, and deduplicates messages', function () {
    $digest = app(TelegramForumDigestBuilder::class)->build('2026-06-17');

    expect($digest['totals']['forums'])->toBe(2)
        ->and($digest['totals']['active_topics'])->toBe(3)
        ->and($digest['totals']['messages'])->toBe(5)
        ->and($digest['data_quality']['duplicate_message_keys'])->toBe(1)
        ->and($digest['data_quality']['private_messages_excluded'])->toBeTrue()
        ->and($digest['data_quality']['messages_without_thread_excluded'])->toBeTrue()
        ->and($digest['forums'][0]['topics'])->toHaveCount(2);
});

it('calculates conservative problem, positive, unresolved and resolved signals', function () {
    $digest = app(TelegramForumDigestBuilder::class)->build('2026-06-17');
    $topics = collect($digest['forums'])->flatMap(fn (array $forum) => $forum['topics'])->keyBy('message_thread_id');

    expect($digest['signals']['problems'])->toBe(3)
        ->and($digest['signals']['positive'])->toBe(2)
        ->and($topics['11']['possible_resolved'])->toBeTrue()
        ->and($topics['12']['repeated_problem'])->toBeTrue()
        ->and($topics['12']['possible_unanswered'])->toBeTrue();
});

it('resolves an optional configured target route without sending anything', function () {
    config([
        'services.telegram.digest_target_chat_id' => '-1001',
        'services.telegram.digest_target_thread_id' => '11',
    ]);

    $digest = app(TelegramForumDigestBuilder::class)->build('2026-06-17');

    expect($digest['route'])->toMatchArray([
        'status' => 'configured',
        'target_chat_id' => '-1001',
        'target_message_thread_id' => '11',
        'target_chat_title' => 'Основной форум',
        'target_topic_title' => 'Смена',
    ]);
});

it('prints a compact JSON contract without message text or raw payload', function () {
    $digestJson = json_encode(app(TelegramForumDigestBuilder::class)->build('2026-06-17'), JSON_UNESCAPED_UNICODE);

    expect($digestJson)->not->toContain('Не работает замок')
        ->and($digestJson)->not->toContain('"raw"')
        ->and($digestJson)->not->toContain('"text"');

    $this->artisan('telegram:forum-digest-preview', ['--date' => '2026-06-17', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"date": "2026-06-17"');
});

it('uses the evening formatter for the human preview without sending Telegram', function () {
    Http::fake();

    $formatter = Mockery::mock(TelegramDigestFormatter::class);
    $formatter->shouldReceive('evening')->once()->andReturn('formatted-evening-preview');
    $this->app->instance(TelegramDigestFormatter::class, $formatter);

    $this->artisan('telegram:forum-digest-preview', ['--date' => '2026-06-17'])
        ->expectsOutput('formatted-evening-preview')
        ->assertExitCode(0);

    expect(Http::recorded())->toHaveCount(0);
});

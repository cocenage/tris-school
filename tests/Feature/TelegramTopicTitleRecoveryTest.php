<?php

use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramTopicTitleResolver;
use App\Services\Telegram\TelegramUpdateIngestService;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
});

afterEach(fn () => TelegramOperationalTestDatabase::purge());

function titleRecoveryMessage(
    TelegramTopic $topic,
    string $storedMessageId,
    array $payload,
    string $sentAt,
): TelegramMessage {
    return TelegramMessage::create([
        'telegram_chat_id' => $topic->telegram_chat_id,
        'telegram_topic_id' => $topic->id,
        'message_id' => $storedMessageId,
        'message_type' => 'unknown',
        'sent_at' => $sentAt,
        'raw' => ['message' => $payload],
    ]);
}

function titleRecoveryTopic(string $threadId = '11', ?string $title = 'Тема #11'): TelegramTopic
{
    $chat = TelegramChat::firstOrCreate(
        ['telegram_chat_id' => '-1001'],
        ['title' => 'Work chat', 'type' => 'supergroup', 'is_enabled' => true],
    );

    return TelegramTopic::create([
        'telegram_chat_id' => $chat->id,
        'telegram_thread_id' => $threadId,
        'title' => $title,
        'is_enabled' => true,
    ]);
}

it('recognizes only exact generated topic placeholders as missing titles', function () {
    $resolver = app(TelegramTopicTitleResolver::class);

    expect($resolver->isMeaningful(null))->toBeFalse()
        ->and($resolver->isMeaningful('   '))->toBeFalse()
        ->and($resolver->isMeaningful('Тема #11'))->toBeFalse()
        ->and($resolver->isMeaningful('Topic 11'))->toBeFalse()
        ->and($resolver->isMeaningful('Квартира 11'))->toBeTrue()
        ->and($resolver->isMeaningful('Via 11'))->toBeTrue();
});

it('preserves a meaningful title when the secondary ingest receives an ordinary message', function () {
    $topic = titleRecoveryTopic(title: 'Via Reale 1');

    app(TelegramUpdateIngestService::class)->ingest([
        'message' => [
            'message_id' => 20,
            'message_thread_id' => 11,
            'date' => now()->timestamp,
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'text' => 'Ordinary message',
        ],
    ]);

    expect($topic->fresh()->title)->toBe('Via Reale 1');
});

it('stores explicit create and edit titles in the secondary ingest path', function () {
    $service = app(TelegramUpdateIngestService::class);
    $base = [
        'message_id' => 11,
        'message_thread_id' => 11,
        'date' => now()->subMinute()->timestamp,
        'chat' => ['id' => -1001, 'type' => 'supergroup'],
    ];

    $service->ingest(['message' => $base + [
        'forum_topic_created' => ['name' => 'Via Prima 1'],
    ]]);
    $service->ingest(['message' => array_replace($base, [
        'message_id' => 12,
        'date' => now()->timestamp,
        'forum_topic_edited' => ['name' => 'Via Ultima 1'],
    ])]);

    expect(TelegramTopic::query()->sole()->title)->toBe('Via Ultima 1');
});

it('recovers the latest direct explicit title', function () {
    $topic = titleRecoveryTopic();

    titleRecoveryMessage($topic, '11', [
        'message_id' => 11,
        'message_thread_id' => 11,
        'date' => 100,
        'chat' => ['id' => -1001],
        'forum_topic_created' => ['name' => 'Via Prima 1'],
    ], '2026-01-01 08:00:00');
    titleRecoveryMessage($topic, '12', [
        'message_id' => 12,
        'message_thread_id' => 11,
        'date' => 200,
        'chat' => ['id' => -1001],
        'forum_topic_edited' => ['name' => 'Via Ultima 1'],
    ], '2026-01-01 09:00:00');

    expect(app(TelegramTopicTitleResolver::class)->resolve($topic))->toBe([
        'status' => 'resolved',
        'title' => 'Via Ultima 1',
        'source' => 'direct',
    ]);
});

it('recovers reply service evidence only when chat and thread identifiers match', function () {
    $topic = titleRecoveryTopic();
    $reply = [
        'message_id' => 21,
        'message_thread_id' => 11,
        'date' => 200,
        'chat' => ['id' => -1001],
        'reply_to_message' => [
            'message_id' => 11,
            'date' => 100,
            'chat' => ['id' => -1001],
            'forum_topic_created' => ['name' => 'Via Reply 1'],
        ],
    ];

    titleRecoveryMessage($topic, '21', $reply, '2026-01-01 09:00:00');

    expect(app(TelegramTopicTitleResolver::class)->resolve($topic))->toBe([
        'status' => 'resolved',
        'title' => 'Via Reply 1',
        'source' => 'reply',
    ]);

    $topic->messages()->delete();
    $reply['message_thread_id'] = 99;
    titleRecoveryMessage($topic, '22', $reply, '2026-01-01 10:00:00');

    expect(app(TelegramTopicTitleResolver::class)->resolve($topic)['status'])->toBe('unresolved');
});

it('allows the same recovered title for separate topic identities', function () {
    $first = titleRecoveryTopic('11', 'Тема #11');
    $second = titleRecoveryTopic('12', 'Тема #12');

    foreach ([[$first, '11'], [$second, '12']] as [$topic, $threadId]) {
        titleRecoveryMessage($topic, $threadId, [
            'message_id' => (int) $threadId,
            'message_thread_id' => (int) $threadId,
            'date' => 100,
            'chat' => ['id' => -1001],
            'forum_topic_created' => ['name' => 'Same valid title'],
        ], '2026-01-01 08:00:00');
    }

    $resolver = app(TelegramTopicTitleResolver::class);

    expect($resolver->resolve($first)['title'])->toBe('Same valid title')
        ->and($resolver->resolve($second)['title'])->toBe('Same valid title');
});

it('keeps dry-run read only and apply updates only deterministic placeholder topics', function () {
    $recoverable = titleRecoveryTopic('11', 'Тема #11');
    titleRecoveryMessage($recoverable, '11', [
        'message_id' => 11,
        'message_thread_id' => 11,
        'date' => 100,
        'chat' => ['id' => -1001],
        'forum_topic_created' => ['name' => 'Via Recoverable 1'],
    ], '2026-01-01 08:00:00');
    $meaningful = titleRecoveryTopic('12', 'Human title');
    titleRecoveryMessage($meaningful, '12', [
        'message_id' => 12,
        'message_thread_id' => 12,
        'date' => 100,
        'chat' => ['id' => -1001],
        'forum_topic_created' => ['name' => 'Historical title'],
    ], '2026-01-01 08:00:00');
    $unresolved = titleRecoveryTopic('13', null);

    $this->artisan('telegram:topics-backfill-titles')
        ->expectsOutputToContain('Dry-run complete. No database writes were performed.')
        ->assertSuccessful();

    expect($recoverable->fresh()->title)->toBe('Тема #11')
        ->and($meaningful->fresh()->title)->toBe('Human title')
        ->and($unresolved->fresh()->title)->toBeNull();

    $this->artisan('telegram:topics-backfill-titles --apply')->assertSuccessful();

    expect($recoverable->fresh()->title)->toBe('Via Recoverable 1')
        ->and($meaningful->fresh()->title)->toBe('Human title')
        ->and($unresolved->fresh()->title)->toBeNull();
});

it('does not write contradictory evidence for the same service event', function () {
    $topic = titleRecoveryTopic();

    foreach ([['21', 'First title'], ['22', 'Second title']] as [$storedId, $title]) {
        titleRecoveryMessage($topic, $storedId, [
            'message_id' => (int) $storedId,
            'message_thread_id' => 11,
            'date' => 200,
            'chat' => ['id' => -1001],
            'reply_to_message' => [
                'message_id' => 11,
                'date' => 100,
                'chat' => ['id' => -1001],
                'forum_topic_created' => ['name' => $title],
            ],
        ], '2026-01-01 09:00:00');
    }

    expect(app(TelegramTopicTitleResolver::class)->resolve($topic)['status'])->toBe('conflict');

    $this->artisan('telegram:topics-backfill-titles --apply')->assertSuccessful();

    expect($topic->fresh()->title)->toBe('Тема #11');
});

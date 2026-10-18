<?php

use App\Http\Controllers\TelegramAnalyticsWebhookController;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\TelegramTopic;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    foreach ([
        '2026_09_27_000000_create_telegram_scheduled_messages_tables.php',
        '2026_10_01_020000_create_scheduled_control_response_tables.php',
        '2026_10_03_000000_allow_replyless_scheduled_control_responses.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    config([
        'services.telegram.analytics_webhook_secret' => 'analytics-test-secret',
        'services.telegram.analytics_bot_token' => 'analytics-poll-token',
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    Http::preventStrayRequests();
});

function fakeAnalyticsPollResponses(): ResponseSequence
{
    $sequence = Http::sequence();
    Http::fake(['api.telegram.org/*' => $sequence]);

    return $sequence;
}

afterEach(function () {
    Schema::dropIfExists('telegram_scheduled_control_summary_deliveries');
    Schema::dropIfExists('telegram_scheduled_message_responses');
    Schema::dropIfExists('telegram_scheduled_message_deliveries');
    Schema::dropIfExists('telegram_scheduled_messages');
    TelegramOperationalTestDatabase::purge();
});

function analyticsWebhookPayload(string $messageId = '601', string $chatType = 'supergroup'): array
{
    return [
        'update_id' => 8000 + (int) $messageId,
        'message' => [
            'message_id' => (int) $messageId,
            'date' => now()->subDay()->timestamp,
            'chat' => ['id' => -1001, 'type' => $chatType, 'title' => 'Work chat'],
            'from' => ['id' => 701, 'first_name' => 'Worker', 'is_bot' => false],
            'text' => 'Проблема с замком',
        ],
    ];
}

it('dispatches the reusable observer job after analytics persistence and attachments', function () {
    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', analyticsWebhookPayload())
        ->assertOk();

    Queue::assertPushed(ProcessTelegramOperationalMessage::class, fn ($job) => $job->mode === 'message'
    );
    expect(TelegramMessage::count())->toBe(1)->and(Http::recorded())->toHaveCount(0);
});

it('preserves Telegram Unix timestamps as absolute UTC instants when Eloquent reads them in Rome', function () {
    $sentAt = CarbonImmutable::parse('2026-07-15 06:00:00', 'UTC');
    $editedAt = CarbonImmutable::parse('2026-07-15 06:15:00', 'UTC');
    $payload = analyticsWebhookPayload('699');
    $payload['message']['date'] = $sentAt->timestamp;

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $payload)->assertOk();

    $editedPayload = $payload;
    $editedPayload['update_id']++;
    $editedPayload['edited_message'] = $editedPayload['message'];
    $editedPayload['edited_message']['edit_date'] = $editedAt->timestamp;
    $editedPayload['edited_message']['text'] = 'Updated text';
    unset($editedPayload['message']);

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $editedPayload)->assertOk();

    $stored = TelegramMessage::query()->where('message_id', '699')->sole();

    $serializedSentAt = $stored->sent_at->toISOString();
    $serializedEditedAt = $stored->edited_at->toISOString();
    $stored->sent_at = $stored->sent_at;
    $stored->edited_at = $stored->edited_at;
    $stored->save();
    $stored->refresh();

    expect($stored->getRawOriginal('sent_at'))->toBe('2026-07-15 06:00:00')
        ->and($stored->getRawOriginal('edited_at'))->toBe('2026-07-15 06:15:00')
        ->and($stored->sent_at->timestamp)->toBe($sentAt->timestamp)
        ->and($stored->edited_at->timestamp)->toBe($editedAt->timestamp)
        ->and($stored->sent_at->toISOString())->toBe($serializedSentAt)
        ->and($stored->edited_at->toISOString())->toBe($serializedEditedAt)
        ->and($stored->sent_at->timezoneName)->toBe('Europe/Rome')
        ->and($stored->sent_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 08:00:00');
});

it('keeps repeated local clock times distinct across the Rome autumn DST transition', function () {
    $first = CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC');
    $second = CarbonImmutable::parse('2026-10-25 01:30:00', 'UTC');

    foreach ([['701', $first], ['702', $second]] as [$messageId, $timestamp]) {
        $payload = analyticsWebhookPayload($messageId);
        $payload['message']['date'] = $timestamp->timestamp;
        $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $payload)->assertOk();
    }

    $messages = TelegramMessage::query()->whereIn('message_id', ['701', '702'])->orderBy('message_id')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->getRawOriginal('sent_at'))->toBe('2026-10-25 00:30:00')
        ->and($messages[1]->getRawOriginal('sent_at'))->toBe('2026-10-25 01:30:00')
        ->and($messages[0]->sent_at->timestamp)->toBe($first->timestamp)
        ->and($messages[1]->sent_at->timestamp)->toBe($second->timestamp)
        ->and($messages[0]->sent_at->format('Y-m-d H:i:s'))->toBe('2026-10-25 02:30:00')
        ->and($messages[1]->sent_at->format('Y-m-d H:i:s'))->toBe('2026-10-25 02:30:00')
        ->and($messages[0]->sent_at->offset)->not->toBe($messages[1]->sent_at->offset);
});

it('keeps duplicate analytics webhook delivery safe by dispatching the same stored message', function () {
    $payload = analyticsWebhookPayload('602');

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $payload)->assertOk();
    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $payload)->assertOk();

    $ids = Queue::pushed(ProcessTelegramOperationalMessage::class)
        ->map(fn ($job) => $job->telegramMessageId)
        ->unique();

    expect(Queue::pushed(ProcessTelegramOperationalMessage::class))->toHaveCount(2)
        ->and($ids)->toHaveCount(1)
        ->and(Http::recorded())->toHaveCount(0);
});

it('does not dispatch analytics observation when the independent flag is disabled', function () {
    config(['services.telegram.operational_observer_enabled' => false]);

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', analyticsWebhookPayload('603'))
        ->assertOk();

    Queue::assertNothingPushed();
});

it('captures scheduled control replies after the analytics webhook persists them', function () {
    $chat = TelegramChat::firstOrCreate(
        ['telegram_chat_id' => '-1001'],
        ['title' => 'Work chat', 'type' => 'supergroup', 'is_enabled' => true],
    );
    $control = TelegramScheduledMessage::create([
        'name' => 'Проверка расписания', 'control_type' => 'schedule_checked', 'telegram_chat_record_id' => $chat->id,
        'message' => 'Время уборок проверено?', 'send_time' => '09:30:00', 'weekdays' => [4], 'enabled' => true,
    ]);
    $delivery = TelegramScheduledMessageDelivery::create([
        'scheduled_message_id' => $control->id, 'control_type' => 'schedule_checked',
        'chat_id' => '-1001', 'message_thread_id' => '11', 'scheduled_for' => now()->subHour(),
        'sent_at' => now()->subHour(), 'telegram_message_id' => 9801, 'status' => 'sent',
    ]);
    $payload = analyticsWebhookPayload('614');
    $payload['message']['date'] = now()->timestamp;
    $payload['message']['message_thread_id'] = 11;
    $payload['message']['reply_to_message'] = ['message_id' => 9801];
    $payload['message']['text'] = 'Да, всё проверено';

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $payload)->assertOk();

    expect(TelegramScheduledMessageResponse::count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::sole()->delivery_id)->toBe($delivery->id);
});

it('persists topic create and edit titles without ordinary messages overwriting them', function () {
    $created = analyticsWebhookPayload('611');
    $created['message']['message_thread_id'] = 611;
    unset($created['message']['text']);
    $created['message']['forum_topic_created'] = ['name' => 'Via Originale 7'];

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $created)->assertOk();

    $ordinary = analyticsWebhookPayload('612');
    $ordinary['message']['message_thread_id'] = 611;
    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $ordinary)->assertOk();

    expect(TelegramTopic::query()->sole()->title)->toBe('Via Originale 7');

    $edited = analyticsWebhookPayload('613');
    $edited['message']['message_thread_id'] = 611;
    unset($edited['message']['text']);
    $edited['message']['forum_topic_edited'] = ['name' => 'Via Rinominata 7'];

    $this->postJson('/telegram/analytics-webhook/analytics-test-secret', $edited)->assertOk();

    expect(TelegramTopic::query()->sole()->title)->toBe('Via Rinominata 7');
});

it('polls analytics messages in update order and acknowledges the completed batch', function () {
    $first = analyticsWebhookPayload('620');
    $first['update_id'] = 100;
    $instant = CarbonImmutable::parse('2026-07-15 06:00:00', 'UTC');
    $first['message']['date'] = $instant->timestamp;
    $second = analyticsWebhookPayload('621');
    $second['update_id'] = 101;

    fakeAnalyticsPollResponses()
        ->push(['ok' => true, 'result' => [$second, $first]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:analytics-poll'))->toBe(0)
        ->and(TelegramMessage::query()->orderBy('id')->pluck('message_id')->all())->toBe(['620', '621']);

    $stored = TelegramMessage::query()->where('message_id', '620')->sole();
    expect($stored->getRawOriginal('sent_at'))->toBe('2026-07-15 06:00:00')
        ->and($stored->sent_at->timestamp)->toBe($instant->timestamp)
        ->and($stored->sent_at->format('Y-m-d H:i:s'))->toBe('2026-07-15 08:00:00');

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['allowed_updates'])->toBe(['message', 'edited_message', 'channel_post', 'edited_channel_post'])
        ->and($requests[1]['offset'])->toBe(102);
});

it('returns cleanly for an empty analytics poll', function () {
    fakeAnalyticsPollResponses()->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:analytics-poll'))->toBe(0)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(TelegramMessage::count())->toBe(0);
});

it('keeps duplicate analytics polling idempotent', function () {
    $payload = analyticsWebhookPayload('622');
    $payload['update_id'] = 102;

    fakeAnalyticsPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:analytics-poll'))->toBe(0)
        ->and(Artisan::call('telegram:analytics-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '622')->count())->toBe(1);
});

it('does not acknowledge past a failed analytics update', function () {
    fakeAnalyticsPollResponses()->push(['ok' => true, 'result' => [
        ['update_id' => 12, 'message' => ['message_id' => 12]],
        ['update_id' => 10, 'message' => ['message_id' => 10]],
        ['update_id' => 11, 'message' => ['message_id' => 11]],
    ]]);

    $processor = Mockery::mock(TelegramAnalyticsWebhookController::class);
    $processor->shouldReceive('processUpdate')->once()->withArgs(fn (array $update): bool => $update['update_id'] === 10)->andReturn(response()->json(['ok' => true]))->ordered();
    $processor->shouldReceive('processUpdate')->once()->withArgs(fn (array $update): bool => $update['update_id'] === 11)->andThrow(new RuntimeException('fixture failure'))->ordered();
    $processor->shouldReceive('processUpdate')->never()->withArgs(fn (array $update): bool => $update['update_id'] === 12);
    $this->app->instance(TelegramAnalyticsWebhookController::class, $processor);

    expect(Artisan::call('telegram:analytics-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 11 failed', 'remain unconfirmed')
        ->and(Http::recorded())->toHaveCount(1);
});

it('fails an analytics API error without processing or advancing the offset', function () {
    fakeAnalyticsPollResponses()->push(['ok' => false, 'description' => 'Conflict'], 409);
    $processor = Mockery::mock(TelegramAnalyticsWebhookController::class);
    $processor->shouldNotReceive('processUpdate');
    $this->app->instance(TelegramAnalyticsWebhookController::class, $processor);

    expect(Artisan::call('telegram:analytics-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('pending updates remain available for retry')
        ->and(Http::recorded())->toHaveCount(1);
});

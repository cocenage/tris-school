<?php

use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\TelegramTopic;
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
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    Http::fake();
});

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

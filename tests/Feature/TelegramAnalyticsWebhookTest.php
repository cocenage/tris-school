<?php

use App\Jobs\ProcessTelegramOperationalMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.analytics_webhook_secret' => 'analytics-test-secret',
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    Http::fake();
});

afterEach(fn () => TelegramOperationalTestDatabase::purge());

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
    expect(Http::recorded())->toHaveCount(0);
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

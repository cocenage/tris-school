<?php

use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramUpdateIngestService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.work_webhook_secret' => 'test-secret',
    ]);

    Cache::flush();
});

function privateWebhookPayload(int $messageId = 42): array
{
    return [
        'update_id' => 1000 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'date' => now()->timestamp,
            'chat' => ['id' => 777, 'type' => 'private'],
            'from' => ['id' => 777, 'first_name' => 'Test'],
            'text' => 'Я заболела',
        ],
    ];
}

it('answers private messages without ingesting or invoking the assistant', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 901]]));

    $ingest = Mockery::mock(TelegramUpdateIngestService::class);
    $ingest->shouldReceive('ingest')->never();
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('handle')->never();
    $this->app->instance(TelegramUpdateIngestService::class, $ingest);
    $this->app->instance(TelegramAssistantService::class, $assistant);

    $response = $this->postJson('/telegram/work-webhook/test-secret', privateWebhookPayload());

    $response->assertOk()->assertJson([
        'ok' => true,
        'skipped' => 'private_message',
    ]);

    Http::assertSent(function (ClientRequest $request): bool {
        return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === 777
            && $request['text'] === 'Я не обрабатываю личные сообщения. Используйте рабочий чат.';
    });
});

it('does not send a duplicate fallback for a repeated private webhook update', function () {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 902]]));

    $payload = privateWebhookPayload(43);
    $first = $this->postJson('/telegram/work-webhook/test-secret', $payload);
    $second = $this->postJson('/telegram/work-webhook/test-secret', $payload);

    $first->assertOk();
    $second->assertOk();
    expect(Http::recorded())->toHaveCount(1);
});

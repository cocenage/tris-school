<?php

use App\Models\User;
use App\Services\Telegram\HtmlBubbles\OksWidgetBuilder;
use App\Services\Telegram\HtmlBubbles\TelegramHtmlBubblesTargetGuard;
use App\Services\Telegram\HtmlBubbles\TelegramWidgetQueryHandler;
use App\Services\Telegram\TelegramPollingFailure;
use App\Services\Telegram\TelegramUpdatePoller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'services.telegram.bot_token' => 'test-work-token',
        'services.telegram.htmlbubbles.enabled' => false,
        'services.telegram.htmlbubbles.test_chat_id' => null,
        'services.telegram.htmlbubbles.test_thread_id' => null,
        'services.telegram.work_allowed_chat_ids' => ['-100111'],
        'services.telegram.operational_chat_ids' => ['-100222'],
        'services.telegram.digest_districts' => [],
    ]);

    Cache::flush();
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('telegram_id')->nullable();
        $table->string('status')->default('pending');
        $table->string('role')->default('cleaner');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Http::preventStrayRequests();
});

it('renders a compact self-contained OКС demo card within Telegram limits', function (): void {
    $builder = app(OksWidgetBuilder::class);
    $html = $builder->html();

    expect($html)
        ->toContain('Lambrate')
        ->toContain('Lodi')
        ->toContain('Certosa')
        ->toContain('Navigli')
        ->toContain('Como')
        ->toContain('demo')
        ->toContain('api.request')
        ->toContain('chatDataChanged')
        ->not->toContain('https://')
        ->not->toContain('http://')
        ->not->toContain('Олеся')
        ->not->toContain('Анастасия');

    expect(strlen($html))->toBeLessThanOrEqual(32768)
        ->and(strlen(gzcompress($html, 9)))->toBeLessThanOrEqual(4096)
        ->and($builder->caption())->toContain('список сменных назначений');

    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $loaded = $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    expect($loaded)->toBeTrue()
        ->and((new DOMXPath($document))->query('//section[@class="districts"]/div')->length)->toBe(5)
        ->and((new DOMXPath($document))->query('//button')->length)->toBe(2);
});

it('does not send when the feature flag is off or the target is not explicitly configured', function (): void {
    expect(Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100999']))->toBe(1);
    Http::assertNothingSent();

    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
        'services.telegram.htmlbubbles.test_thread_id' => 17,
    ]);

    expect(Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100998']))->toBe(1);
    expect(Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100111']))->toBe(1);
    expect(Artisan::call('telegram:htmlbubbles:send-test', [
        '--chat-id' => '-100999',
        '--message-thread-id' => 18,
    ]))->toBe(1);
    Http::assertNothingSent();
});

it('does not fall back to a normal Telegram message when the beta sender rejects the widget', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
    ]);

    Http::fake(fn () => Http::response(['ok' => false, 'error_code' => 404], 404));

    expect(Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100999']))->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/sendWidget'));
    Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage'));
});

it('sends only to the configured test chat and its separately configured topic', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
        'services.telegram.htmlbubbles.test_thread_id' => 17,
    ]);

    $guard = app(TelegramHtmlBubblesTargetGuard::class);
    expect($guard->configuredChatId())->toBe('-100999')
        ->and($guard->configuredThreadId())->toBe(17)
        ->and($guard->isKnownOperationalChat('-100999'))->toBeFalse()
        ->and($guard->isKnownOperationalChat('-100111'))->toBeTrue()
        ->and($guard->isKnownOperationalChat('-100222'))->toBeTrue()
        ->and($guard->allowsConfiguredTarget('-100999', '17'))->toBeTrue();

    Http::fake([
        'https://api.telegram.org/beta/bottest-work-token/test/sendWidget' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 41, 'chat' => ['id' => -100999]],
        ]),
    ]);

    expect(Artisan::call('telegram:htmlbubbles:send-test', [
        '--chat-id' => '-100999',
        '--message-thread-id' => 17,
    ]))->toBe(0, Artisan::output());

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/sendWidget')
        && $request['chat_id'] === '-100999'
        && $request['message_thread_id'] === 17
        && $request['allow_queries'] === true
        && $request['width'] === 0
        && $request['height'] === 0
        && isset($request['html'], $request['chat_data']));
});

it('answers a widget query without trusting user ids in the payload or accessing business data', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
    ]);

    Http::fake(fn (ClientRequest $request) => Http::response([
        'ok' => true,
        'result' => str_ends_with($request->url(), '/test/sendWidget')
            ? ['message_id' => 41, 'chat' => ['id' => -100999]]
            : true,
    ]));

    expect(Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100999']))->toBe(0, Artisan::output());

    $response = app(TelegramWidgetQueryHandler::class)->handle([
        'id' => 'query-access-1',
        'from' => ['id' => 999],
        'chat' => ['id' => -100999],
        'message_id' => 41,
        'data' => 'access:check',
        'telegram_id' => 123,
    ]);

    expect($response->getData(true))->toMatchArray(['ok' => true, 'handled' => true]);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/answerWidgetQuery')
        && $request['widget_query_id'] === 'query-access-1'
        && str_contains($request['result'], 'не подтверждён'));
    Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/editMessageWidgetData'));
});

it('routes widget queries through the existing Work webhook only when enabled and on the test chat', function (): void {
    config([
        'services.telegram.work_webhook_secret' => 'work-test-secret',
        'services.telegram.htmlbubbles.enabled' => false,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
    ]);

    $this->postJson('/api/telegram/work-webhook/work-test-secret', [
        'widget_query' => ['id' => 'disabled-query', 'chat' => ['id' => -100999]],
    ])->assertOk()->assertJson(['ok' => true, 'skipped' => 'htmlbubbles_disabled']);
    Http::assertNothingSent();

    config(['services.telegram.htmlbubbles.enabled' => true]);
    $guard = app(TelegramHtmlBubblesTargetGuard::class);
    Cache::put($guard->messageCacheKey('-100999', 42), ['status' => 'prepared'], now()->addHours(24));

    Http::fake([
        'https://api.telegram.org/beta/bottest-work-token/test/answerWidgetQuery' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $this->postJson('/api/telegram/work-webhook/work-test-secret', [
        'widget_query' => [
            'id' => 'webhook-query',
            'from' => ['id' => 456],
            'chat' => ['id' => -100999],
            'message_id' => 42,
            'data' => 'access:check',
        ],
    ])->assertOk()->assertJson(['ok' => true, 'handled' => true]);

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/answerWidgetQuery'));
});

it('allows only an approved admin to update demo state on the original test message, once', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
    ]);

    User::create([
        'name' => 'Test admin',
        'telegram_id' => '123',
        'status' => 'approved',
        'role' => 'admin',
        'is_active' => true,
    ]);
    User::create([
        'name' => 'Test supervisor',
        'telegram_id' => '456',
        'status' => 'approved',
        'role' => 'supervisor',
        'is_active' => true,
    ]);

    Http::fake(fn (ClientRequest $request) => Http::response([
        'ok' => true,
        'result' => str_ends_with($request->url(), '/test/sendWidget')
            ? ['message_id' => 41, 'chat' => ['id' => -100999]]
            : true,
    ]));

    $send = Artisan::call('telegram:htmlbubbles:send-test', ['--chat-id' => '-100999']);
    expect($send)->toBe(0, Artisan::output());

    $update = [
        'id' => 'query-demo-1',
        'from' => ['id' => 123],
        'chat' => ['id' => -100999],
        'message_id' => 41,
        'data' => 'demo:complete',
        'telegram_id' => 999,
    ];

    app(TelegramWidgetQueryHandler::class)->handle($update);
    app(TelegramWidgetQueryHandler::class)->handle($update);

    app(TelegramWidgetQueryHandler::class)->handle([
        ...$update,
        'id' => 'query-demo-supervisor',
        'from' => ['id' => 456],
    ]);

    Http::assertSentCount(4);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/test/editMessageWidgetData')
        && $request['chat_id'] === '-100999'
        && $request['message_id'] === 41
        && str_contains($request['chat_data'], 'complete'));
});

it('does not confirm a Work update when the experimental beta endpoint rejects getUpdates', function (): void {
    Http::fake([
        'https://api.telegram.org/beta/bottest-work-token/test/getUpdates' => Http::response(['ok' => false, 'description' => 'method unavailable'], 404),
        'https://api.telegram.org/bottest-work-token/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    expect(fn () => app(TelegramUpdatePoller::class)->poll(
        token: 'test-work-token',
        allowedUpdates: ['message', 'callback_query', 'widget_query'],
        limit: 10,
        processor: static fn (array $update): null => null,
        pollerName: 'work',
        experimentalWidgets: true,
    ))->toThrow(TelegramPollingFailure::class);

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/beta/')
        && in_array('widget_query', $request['allowed_updates'], true));
});

it('keeps the same offset across bounded beta connection retries and does not use stable polling', function (): void {
    $betaRequests = [];

    Http::fake(function (ClientRequest $request) use (&$betaRequests): Response {
        if (str_contains($request->url(), '/beta/')) {
            $betaRequests[] = [
                'url' => $request->url(),
                'offset' => $request['offset'],
                'allowed_updates' => $request['allowed_updates'],
            ];

            throw new ConnectionException('simulated connection loss');
        }

        return Http::response(['ok' => true, 'result' => []]);
    });

    expect(fn () => app(TelegramUpdatePoller::class)->poll(
        token: 'test-work-token',
        allowedUpdates: ['message', 'widget_query'],
        limit: 10,
        processor: static fn (array $item): null => null,
        offset: 50,
        pollerName: 'work',
        experimentalWidgets: true,
    ))->toThrow(TelegramPollingFailure::class);

    expect($betaRequests)->toHaveCount(2)
        ->and($betaRequests[0]['url'])->toContain('/beta/')
        ->and($betaRequests[1]['url'])->toContain('/beta/')
        ->and($betaRequests[0]['offset'])->toBe(50)
        ->and($betaRequests[1]['offset'])->toBe(50)
        ->and($betaRequests[0]['allowed_updates'])->toContain('widget_query')
        ->and($betaRequests[1]['allowed_updates'])->toContain('widget_query');
});

it('opts the existing Work poll command into beta updates only behind the feature flag', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
        'services.telegram.bot_token' => 'test-work-token',
    ]);

    Http::fake([
        'https://api.telegram.org/beta/bottest-work-token/test/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    expect(Artisan::call('telegram:work-poll', ['--limit' => 10]))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/beta/')
        && in_array('widget_query', $request['allowed_updates'], true));
});

it('leaves the failed beta batch pending instead of trying a potentially lossy stable filter', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => '-100999',
    ]);

    Http::fake([
        'https://api.telegram.org/beta/bottest-work-token/test/getUpdates' => Http::response(['ok' => false], 404),
        'https://api.telegram.org/bottest-work-token/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    expect(Artisan::call('telegram:work-poll', ['--limit' => 10]))->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/beta/'));
});

it('keeps stable Work polling when the feature flag is on without a safe test destination', function (): void {
    config([
        'services.telegram.htmlbubbles.enabled' => true,
        'services.telegram.htmlbubbles.test_chat_id' => null,
    ]);

    Http::fake([
        'https://api.telegram.org/bottest-work-token/getUpdates' => Http::response(['ok' => true, 'result' => []]),
    ]);

    expect(Artisan::call('telegram:work-poll', ['--limit' => 10]))->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => ! str_contains($request->url(), '/beta/')
        && ! in_array('widget_query', $request['allowed_updates'], true));
});

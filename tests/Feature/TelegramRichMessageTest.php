<?php

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use App\Services\Forms\DayOffRequestTelegramService;
use App\Services\Forms\VacationRequestTelegramService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramRichMessageBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.rich_messages_enabled' => true,
    ]);
});

it('uses only the legacy HTML transport when Rich Messages are disabled', function (): void {
    config(['services.telegram.rich_messages_enabled' => false]);
    Http::fake(fn () => Http::response([
        'ok' => true,
        'result' => ['message_id' => 504],
    ]));

    $messageId = app(TelegramBotService::class)->sendRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Не отправляется в rich endpoint'],
        fallbackHtml: '<b>Legacy</b>',
    );

    expect($messageId)->toBe(504);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['text'] === '<b>Legacy</b>'
    );
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendRichMessage')
    );
});

it('forces only Telegram API requests to the configured address family', function (): void {
    $optionsByHost = [];
    Http::fake(function (Request $request, array $options) use (&$optionsByHost) {
        $optionsByHost[$request->toPsrRequest()->getUri()->getHost()] = $options;

        return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
    });

    expect(app(TelegramBotService::class)->sendMessage('-100123', 'Тест'))
        ->toBe(99);

    Http::post('https://weather.example.test/forecast', []);

    expect($optionsByHost['api.telegram.org']['force_ip_resolve'])->toBe('v6')
        ->and($optionsByHost['weather.example.test'])->not->toHaveKey('force_ip_resolve');
});

it('logs safe Bot API failure metadata without message text or destination details', function (): void {
    config(['services.telegram.analytics_bot_token' => 'diagnostic-secret-token']);
    Log::spy();
    Http::fake(fn () => Http::response([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: chat not found',
    ], 200));

    expect(app(TelegramBotService::class)->sendAnalyticsMessage('-100123456789', 'private message text', '456'))
        ->toBeNull();

    Log::shouldHaveReceived('info')->once()->with('Telegram sendMessage response', Mockery::on(
        fn (array $context): bool => $context['status'] === 200
            && $context['successful'] === true
            && $context['api_ok'] === false
            && $context['api_error_code'] === 400
            && $context['receipt_present'] === false
            && ! array_key_exists('chat_id', $context)
            && ! array_key_exists('text', $context)
            && ! str_contains(json_encode($context), 'diagnostic-secret-token')
            && ! str_contains(json_encode($context), 'private message text')
            && ! str_contains(json_encode($context), '-100123456789')
    ));
});

it('normalizes a numeric Telegram address-family setting to IPv6', function (): void {
    $previousEnvironment = getenv('TELEGRAM_IP_RESOLVE');
    $hadServerValue = array_key_exists('TELEGRAM_IP_RESOLVE', $_SERVER);
    $hadEnvValue = array_key_exists('TELEGRAM_IP_RESOLVE', $_ENV);
    $previousServerValue = $_SERVER['TELEGRAM_IP_RESOLVE'] ?? null;
    $previousEnvValue = $_ENV['TELEGRAM_IP_RESOLVE'] ?? null;

    putenv('TELEGRAM_IP_RESOLVE=6');
    $_SERVER['TELEGRAM_IP_RESOLVE'] = '6';
    $_ENV['TELEGRAM_IP_RESOLVE'] = '6';

    try {
        $telegramConfig = require config_path('services.php');
    } finally {
        $previousEnvironment === false
            ? putenv('TELEGRAM_IP_RESOLVE')
            : putenv('TELEGRAM_IP_RESOLVE='.$previousEnvironment);

        if ($hadServerValue) {
            $_SERVER['TELEGRAM_IP_RESOLVE'] = $previousServerValue;
        } else {
            unset($_SERVER['TELEGRAM_IP_RESOLVE']);
        }

        if ($hadEnvValue) {
            $_ENV['TELEGRAM_IP_RESOLVE'] = $previousEnvValue;
        } else {
            unset($_ENV['TELEGRAM_IP_RESOLVE']);
        }
    }

    expect($telegramConfig['telegram']['ip_resolve'])->toBe('v6');
});

it('does not expose the bot token in connection exceptions', function (): void {
    $token = 'local-test-token-must-not-leak';
    config(['services.telegram.scheduled_bot_token' => $token]);
    Http::fake(HttpClientFactory::failedConnection(
        "cURL connection timeout for https://api.telegram.org/bot{$token}/sendMessage",
    ));

    $exception = null;

    try {
        app(TelegramBotService::class)->sendScheduledMessage('-100123', 'Тест');
    } catch (ConnectionException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ConnectionException::class)
        ->and($exception->getMessage())->not->toContain($token)
        ->and($exception->getPrevious())->toBeNull();
});

it('uses only legacy HTML editing when Rich Messages are disabled', function (): void {
    config(['services.telegram.rich_messages_enabled' => false]);
    Http::fake(fn () => Http::response(['ok' => true]));

    expect(app(TelegramBotService::class)->editMessage(
        chatId: '-100123',
        messageId: 57,
        richMessage: ['markdown' => '# Не отправляется в rich endpoint'],
        fallbackHtml: '<b>Legacy edit</b>',
        replyMarkup: ['inline_keyboard' => []],
    ))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
        && $request['text'] === '<b>Legacy edit</b>'
        && ($request['reply_markup']['inline_keyboard'] ?? null) === []
    );
    Http::assertNotSent(fn (Request $request): bool => isset($request['rich_message'])
    );
});

it('creates a rich message without changing inline callback data', function (): void {
    Http::fake(fn () => Http::response([
        'ok' => true,
        'result' => ['message_id' => 501],
    ]));

    $rich = app(TelegramRichMessageBuilder::class)->build(
        title: 'Заявка на выходной',
        status: 'Ожидает решения',
        fields: ['Сотрудник' => 'Александра', 'Даты' => '12–13 августа'],
        body: 'Личные обстоятельства',
        notice: 'Заявка ожидает решения администратора или супервайзера.',
    );
    $keyboard = ['inline_keyboard' => [[
        ['text' => 'Одобрить', 'callback_data' => 'dayoffday:approve:77'],
        ['text' => 'Отклонить', 'callback_data' => 'dayoffday:reject:77'],
    ]]];

    $messageId = app(TelegramBotService::class)->sendRichMessage(
        chatId: '-100123',
        richMessage: $rich,
        fallbackHtml: '<b>Заявка на выходной</b>',
        replyMarkup: $keyboard,
    );

    expect($messageId)->toBe(501);
    Http::assertSent(function (Request $request) use ($rich, $keyboard): bool {
        return str_ends_with($request->url(), '/sendRichMessage')
            && ($request['rich_message'] ?? null) === $rich
            && ($request['reply_markup'] ?? null) === $keyboard;
    });
});

it('renders a day-off application through the shared rich transport', function (): void {
    config([
        'services.telegram.chat_id_formweekend' => '-100123',
        'services.telegram.thread_id_formweekend' => '17',
    ]);
    Http::fake(fn () => Http::response([
        'ok' => true,
        'result' => ['message_id' => 503],
    ]));

    $request = new DayOffRequest(['reason' => 'Личные обстоятельства', 'status' => 'pending']);
    $request->id = 88;
    $request->exists = true;
    $request->setRelation('user', new User(['name' => 'Александра', 'telegram_id' => '999']));
    $request->setRelation('days', collect([
        tap(new DayOffRequestDay(['date' => '2026-08-12', 'status' => 'pending']), function (DayOffRequestDay $day): void {
            $day->id = 77;
        }),
    ]));

    app(DayOffRequestTelegramService::class)->sendCreated($request);

    Http::assertSent(function (Request $httpRequest): bool {
        return str_ends_with($httpRequest->url(), '/sendRichMessage')
            && ($httpRequest['rich_message']['markdown'] ?? '') !== ''
            && ($httpRequest['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'dayoffday:approve:77'
            && ($httpRequest['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'dayoffday:reject:77';
    });
});

dataset('request result notices', [
    'day off approved' => [DayOffRequestTelegramService::class, 'approved'],
    'day off rejected' => [DayOffRequestTelegramService::class, 'rejected'],
    'vacation approved' => [VacationRequestTelegramService::class, 'approved'],
    'vacation rejected' => [VacationRequestTelegramService::class, 'rejected'],
]);

it('opens DayOff and Vacation result notices through the authenticated Telegram Mini App', function (string $serviceClass, string $status): void {
    config([
        'app.url' => 'https://academy.example.test',
        'services.day_off.profile_url' => 'https://academy.example.test/profile/applications',
        'services.day_off.admin_chat_url' => 'https://t.me/admin_help',
        'services.vacation.admin_chat_url' => 'https://t.me/admin_help',
    ]);
    URL::forceRootUrl('https://academy.example.test');
    URL::forceScheme('https');
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 507]]));

    $request = $serviceClass === DayOffRequestTelegramService::class
        ? new DayOffRequest(['reason' => 'Test', 'status' => $status])
        : new VacationRequest(['reason' => 'Test', 'status' => $status]);
    $request->id = 88;
    $request->exists = true;
    $request->setRelation('user', new User(['name' => 'Employee', 'telegram_id' => '999']));
    $day = $serviceClass === DayOffRequestTelegramService::class
        ? new DayOffRequestDay(['date' => '2026-11-02', 'status' => $status])
        : new VacationRequestDay(['date' => '2026-11-02', 'status' => $status]);
    $day->id = 77;
    $request->setRelation('days', collect([$day]));

    app($serviceClass)->sendResult($request);

    Http::assertSent(fn (Request $httpRequest): bool => str_ends_with($httpRequest->url(), '/sendRichMessage')
        && ($httpRequest['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? null) === 'https://academy.example.test'
        && ! isset($httpRequest['reply_markup']['inline_keyboard'][0][0]['url'])
        && ($httpRequest['reply_markup']['inline_keyboard'][0][1]['url'] ?? null) === 'https://t.me/admin_help'
        && ! str_contains((string) ($httpRequest['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? ''), 'token')
    );
})->with('request result notices');

it('falls back to the existing HTML message when rich sending is rejected', function (): void {
    Http::fakeSequence()
        ->push(['ok' => false, 'description' => 'unsupported'], 400)
        ->push(['ok' => true, 'result' => ['message_id' => 502]], 200);

    $messageId = app(TelegramBotService::class)->sendRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Заявка'],
        fallbackHtml: '<b>Заявка</b>',
    );

    expect($messageId)->toBe(502)
        ->and(Http::recorded())->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['text'] === '<b>Заявка</b>'
        && $request['parse_mode'] === 'HTML'
    );
});

it('updates a decision as rich content and removes action buttons', function (): void {
    Http::fake(fn () => Http::response(['ok' => true]));

    $updated = app(TelegramBotService::class)->editMessage(
        chatId: '-100123',
        messageId: 55,
        richMessage: ['markdown' => "# Заявка на выходной\n\n**Одобрено**"],
        fallbackHtml: '<b>Одобрено</b>',
        replyMarkup: ['inline_keyboard' => []],
    );

    expect($updated)->toBeTrue();
    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/editMessageText')
            && isset($request['rich_message'])
            && ($request['reply_markup']['inline_keyboard'] ?? null) === [];
    });
});

it('falls back to HTML when rich editing is rejected', function (): void {
    Http::fakeSequence()
        ->push(['ok' => false], 400)
        ->push(['ok' => true], 200);

    $updated = app(TelegramBotService::class)->editMessage(
        chatId: '-100123',
        messageId: 56,
        richMessage: ['markdown' => '# Отклонено'],
        fallbackHtml: '<b>Отклонено</b>',
        replyMarkup: ['inline_keyboard' => []],
    );

    expect($updated)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
        && ($request['text'] ?? null) === '<b>Отклонено</b>'
        && ($request['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

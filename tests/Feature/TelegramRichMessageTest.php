<?php

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramRichMessageBuilder;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Services\Forms\DayOffRequestTelegramService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
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

    Http::assertSent(fn (Request $request): bool =>
        str_ends_with($request->url(), '/sendMessage')
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
    Http::assertSent(fn (Request $request): bool =>
        str_ends_with($request->url(), '/editMessageText')
        && ($request['text'] ?? null) === '<b>Отклонено</b>'
        && ($request['reply_markup']['inline_keyboard'] ?? null) === []
    );
});

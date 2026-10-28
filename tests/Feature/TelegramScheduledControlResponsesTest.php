<?php

use App\Jobs\DeliverScheduledControlFollowup;
use App\Jobs\DeliverScheduledControlSummary;
use App\Jobs\DeliverScheduledTelegramMessage;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramScheduledControlFollowup;
use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\TelegramTopic;
use App\Services\Telegram\ScheduledControlResponseClassifier;
use App\Services\Telegram\ScheduledControlResponseInterpreter;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramAssistantService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramScheduledControlFollowupService;
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramScheduledControlStatistics;
use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDeliveryService;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use App\Services\Telegram\TelegramScheduledInboundProcessor;
use App\Services\Telegram\TelegramScheduledMessageDeliveryService;
use App\Services\Telegram\TelegramUpdateIngestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'queue.default' => 'database', 'queue.connections.database.connection' => null]);
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    foreach (['2026_09_27_000000_create_telegram_scheduled_messages_tables.php', '2026_10_01_020000_create_scheduled_control_response_tables.php', '2026_10_03_000000_allow_replyless_scheduled_control_responses.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    (require database_path('migrations/2026_10_14_000000_support_scheduled_control_button_responses.php'))->up();
    (require database_path('migrations/2026_10_18_000000_add_district_answers_to_scheduled_controls.php'))->up();
    (require database_path('migrations/2026_10_27_000000_add_scheduled_control_followups.php'))->up();
    config([
        'services.telegram.main_bot_auto_replies_enabled' => false,
        'services.telegram.work_webhook_secret' => 'work-test',
        'services.telegram.work_allowed_chat_ids' => ['-1001'],
        'services.telegram.scheduled_webhook_secret' => 'scheduled-test',
        'services.telegram.scheduled_webhook_allowed_chat_ids' => [],
        'services.telegram.scheduled_response_window_minutes' => 60,
        'services.telegram.scheduled_summary_buffer_minutes' => 5,
        'services.telegram.scheduled_summary_cutoff' => '22:00',
        'services.telegram.scheduled_summary_enabled' => true,
        'services.telegram.scheduled_summary_chat_id' => '-2001',
        'services.telegram.scheduled_summary_thread_id' => '17',
        'services.telegram.digest_districts' => [
            'navigli' => ['label' => 'Navigli'],
            'lodi' => ['label' => 'Lodi'],
            'certosa' => ['label' => 'Certosa'],
            'lambrate' => ['label' => 'Lambrate'],
            'como' => ['label' => 'Como'],
        ],
    ]);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    Http::fake();
    Bus::fake([DeliverScheduledControlSummary::class]);
    $assistant = Mockery::mock(TelegramAssistantService::class);
    $assistant->shouldReceive('isActivated')->andReturnFalse();
    $assistant->shouldNotReceive('handle');
    $this->app->instance(TelegramAssistantService::class, $assistant);
});

afterEach(function () {
    $this->travelBack();
    DB::purge('sqlite');
    DB::purge('analytics');
});

function controlDeliveryFixture(array $overrides = []): TelegramScheduledMessageDelivery
{
    $chat = TelegramChat::firstOrCreate(['telegram_chat_id' => '-1001'], ['title' => 'Test control chat', 'type' => 'supergroup', 'is_enabled' => true]);
    $controlType = $overrides['control_type'] ?? 'first_cleanings_started';
    $control = TelegramScheduledMessage::create([
        'name' => 'Начало уборок', 'control_type' => $controlType, 'telegram_chat_record_id' => $chat->id,
        'message' => 'Все первые уборки начались?', 'send_time' => '09:30:00', 'weekdays' => [4], 'enabled' => true,
    ]);

    return TelegramScheduledMessageDelivery::create(array_replace([
        'scheduled_message_id' => $control->id, 'control_type' => $control->control_type,
        'chat_id' => '-1001', 'message_thread_id' => '11', 'scheduled_for' => '2026-10-01 09:30:00',
        'sent_at' => '2026-10-01 09:35:00', 'telegram_message_id' => 900 + $control->id, 'status' => 'sent',
    ], $overrides));
}

function stubControlSummaryFormatter(): void
{
    $formatter = Mockery::mock(TelegramScheduledControlSummaryFormatter::class);
    $formatter->shouldReceive('format')->andReturn('Test control summary');
    app()->instance(TelegramScheduledControlSummaryFormatter::class, $formatter);
}

function controlReplyPayload(TelegramScheduledMessageDelivery $delivery, string $text = 'Да, все начали', int $id = 100, int $author = 101, string $at = '2026-10-01 09:41:00'): array
{
    return ['update_id' => 1000 + $id, 'message' => [
        'message_id' => $id, 'date' => CarbonImmutable::parse($at, 'Europe/Rome')->timestamp,
        'chat' => ['id' => (int) $delivery->chat_id, 'type' => 'supergroup', 'title' => 'Test control chat'],
        'message_thread_id' => (int) $delivery->message_thread_id,
        'reply_to_message' => ['message_id' => $delivery->telegram_message_id],
        'from' => ['id' => $author, 'first_name' => 'Test staff', 'username' => 'test_staff', 'is_bot' => false],
        'text' => $text,
    ]];
}

function captureControlReply(array $payload): ?TelegramScheduledMessageResponse
{
    $stored = app(TelegramUpdateIngestService::class)->ingest($payload);

    return app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']);
}

function scheduledControlCallback(TelegramScheduledMessageDelivery $delivery, string $queryId, string $answer, int $userId = 701): array
{
    return ['id' => $queryId, 'from' => ['id' => $userId, 'first_name' => 'Duty', 'username' => 'duty', 'is_bot' => false],
        'message' => [
            'message_id' => $delivery->telegram_message_id,
            'message_thread_id' => $delivery->message_thread_id === null ? null : (int) $delivery->message_thread_id,
            'chat' => ['id' => (int) $delivery->chat_id, 'type' => 'supergroup'],
        ],
        'data' => 'sc:'.$delivery->id.':'.$answer,
    ];
}

it('keeps independent district answers and every change by one supervisor', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $service = app(TelegramScheduledControlResponseService::class);
    $answer = function (string $id, string $district, string $choice, int $userId) use ($delivery, $service): array {
        $callback = scheduledControlCallback($delivery, $id, $choice, $userId);
        $callback['data'] = 'sc:'.$delivery->id.':'.$district.':'.$choice;

        return $service->captureCallback($callback);
    };

    expect($answer('district-1', 'lambrate', 'yes', 701)['status'])->toBe('saved')
        ->and($answer('district-2', 'lambrate', 'no', 702)['status'])->toBe('saved')
        ->and($answer('district-3', 'lambrate', 'no', 701)['status'])->toBe('saved')
        ->and($answer('district-3', 'lambrate', 'no', 701)['status'])->toBe('already_answered');

    $answers = $delivery->responses()->orderBy('id')->get();
    expect($answers)->toHaveCount(3)
        ->and($answers->pluck('district_key')->all())->toBe(['lambrate', 'lambrate', 'lambrate'])
        ->and($answers->pluck('telegram_user_id')->all())->toBe(['701', '702', '701'])
        ->and($answers->pluck('text')->all())->toBe(['Да', 'Нет', 'Нет']);
});

it('keeps an explicit courier question as a linked comment with Como and no invented yes or no', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $payload = controlReplyPayload($delivery, 'Курьеров на Комо не было, закрывать уборки или еще будут они?', 321);

    $response = captureControlReply($payload);

    expect($response)->not->toBeNull()
        ->and($response->delivery_id)->toBe($delivery->id)
        ->and($response->district_key)->toBe('como')
        ->and($response->response_kind)->toBe('comment')
        ->and($response->classification)->toBe('unclear')
        ->and($response->text)->toBe($payload['message']['text'])
        ->and($response->telegram_message_id)->toBe('321');
});

it('reserves new canonical controls for the Work bot and leaves informational messages on Scheduled bot', function () {
    $chat = TelegramChat::create(['telegram_chat_id' => '-1001', 'title' => 'Controls', 'type' => 'supergroup', 'is_enabled' => true]);
    $control = TelegramScheduledMessage::create([
        'name' => 'Control', 'control_type' => 'couriers_completed', 'telegram_chat_record_id' => $chat->id,
        'message' => 'Курьеры завершили доставки?', 'send_time' => '12:00:00', 'weekdays' => [4], 'enabled' => true,
    ]);
    $notice = TelegramScheduledMessage::create([
        'name' => 'Notice', 'control_type' => 'control_question', 'telegram_chat_record_id' => $chat->id,
        'message' => 'Информация', 'send_time' => '12:00:00', 'weekdays' => [4], 'enabled' => true,
    ]);
    config(['queue.default' => 'database']);
    Bus::fake();

    app(TelegramScheduledMessageDeliveryService::class)->sendDue();

    expect(TelegramScheduledMessageDelivery::where('scheduled_message_id', $control->id)->value('bot_type'))->toBe('work')
        ->and(TelegramScheduledMessageDelivery::where('scheduled_message_id', $notice->id)->value('bot_type'))->toBe('scheduled');
});

it('keeps the 17:30 closing cleanings and conditional payments as distinct types', function () {
    expect(ScheduledControlTypes::CLOSING_CLEANINGS)->not->toBe(ScheduledControlTypes::CLOSING_PAYMENTS)
        ->and(ScheduledControlTypes::CLOSING_CLEANINGS)->not->toBe('second_cleanings_finishing')
        ->and(ScheduledControlTypes::scheduledTime(ScheduledControlTypes::CLOSING_CLEANINGS, '17:30'))->toBe('17:30')
        ->and(ScheduledControlTypes::scheduledTime(ScheduledControlTypes::SECOND_CLEANINGS_FINISHING, '14:00'))->toBe('14:00')
        ->and(ScheduledControlTypes::scheduledTime(ScheduledControlTypes::CLOSING_PAYMENTS, '18:00'))->toBeNull();
});

it('does not choose a wrong payment template while two active definitions share the payment type', function () {
    Bus::fake();
    $cleanings = controlDeliveryFixture(['control_type' => ScheduledControlTypes::CLOSING_CLEANINGS, 'bot_type' => 'work']);
    closingPaymentTemplateFor($cleanings);
    closingPaymentTemplateFor($cleanings);
    $this->travelTo(Carbon::parse('2026-10-01 19:00:00', 'Europe/Rome'));
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        $callback = scheduledControlCallback($cleanings, 'ambiguous-'.$district, 'yes');
        $callback['data'] = 'sc:'.$cleanings->id.':'.$district.':yes';
        app(TelegramScheduledControlResponseService::class)->captureCallback($callback);
    }

    expect(app(TelegramScheduledMessageDeliveryService::class)->queueClosingPayments($cleanings))->toBeFalse()
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
});

it('sends a new control through Work bot with five district answer rows', function () {
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.scheduled_bot_token' => null]);
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work', 'status' => 'pending', 'telegram_message_id' => null]);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 991]], 200)]);

    (new DeliverScheduledTelegramMessage($delivery->id, CarbonImmutable::now()->addHour()))->handle(app(TelegramBotService::class));

    expect($delivery->fresh()->failure_reason)->toBeNull()
        ->and($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->telegram_message_id)->toBe(991);
    Http::assertSent(function ($request) use ($delivery): bool {
        $keyboard = $request['reply_markup']['inline_keyboard'] ?? [];

        return str_contains($request->url(), '/botwork-test-token/sendMessage')
            && count($keyboard) === 5
            && $keyboard[0][0]['callback_data'] === 'sc:'.$delivery->id.':lambrate:yes'
            && $keyboard[4][1]['callback_data'] === 'sc:'.$delivery->id.':como:no';
    });
});

it('renders conflicting current district answers and linked comments without losing answer history', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $service = app(TelegramScheduledControlResponseService::class);
    foreach ([['one', 'yes', 701], ['two', 'no', 702], ['three', 'yes', 701]] as [$id, $choice, $user]) {
        $callback = scheduledControlCallback($delivery, $id, $choice, $user);
        $callback['data'] = 'sc:'.$delivery->id.':como:'.$choice;
        $service->captureCallback($callback);
    }
    captureControlReply(controlReplyPayload($delivery, 'Курьеров на Комо не было, закрывать уборки?', 321));

    $text = app(TelegramScheduledControlSummaryFormatter::class)->formatInteractive($delivery->fresh());

    expect($text)->toContain('Como — расхождение:', '✅ Да — Duty', '❌ Нет — Duty', 'Курьеров на Комо не было', 't.me/c/')
        ->toContain('Lambrate — нет ответа', 'Lodi — нет ответа', 'Certosa — нет ответа', 'Navigli — нет ответа')
        ->not->toContain('701', '702');
    expect($delivery->responses()->count())->toBe(4);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $districts = collect($summary['controls'])->firstWhere('control_type', 'couriers_completed')['district_results'];
    expect(collect($districts)->firstWhere('district', 'Como')['status'])->toBe('conflict');
});

it('shows a linked question without turning it into a district yes or no', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    captureControlReply(controlReplyPayload($delivery, 'Курьеров на Комо не было, закрывать уборки или ещё будут?', 501));

    $text = app(TelegramScheduledControlSummaryFormatter::class)->formatInteractive($delivery);

    expect($text)->toContain('Como — нет ответа', 'Курьеров на Комо не было', 't.me/c/')
        ->not->toContain('Como — ❌ Нет');
});

it('routes a Work bot district callback through polling and updates its original message', function () {
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true], 200)]);
    $callback = scheduledControlCallback($delivery, 'work-district-answer', 'yes');
    $callback['data'] = 'sc:'.$delivery->id.':lodi:yes';

    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();

    expect($delivery->responses()->count())->toBe(1)
        ->and($delivery->responses()->first()->district_key)->toBe('lodi');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botwork-test-token/answerCallbackQuery')
        && $request['callback_query_id'] === 'work-district-answer');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botwork-test-token/editMessageText')
        && (int) $request['message_id'] === (int) $delivery->telegram_message_id);
});

it('rejects stale and wrong-topic Work control buttons without creating answers', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $callback = scheduledControlCallback($delivery, 'wrong-topic', 'yes');
    $callback['data'] = 'sc:'.$delivery->id.':lodi:yes';
    $callback['message']['message_thread_id'] = 999;
    expect(app(TelegramScheduledControlResponseService::class)->captureCallback($callback)['status'])->toBe('unmatched');

    $callback['message']['message_thread_id'] = 11;
    $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'Europe/Rome'));
    expect(app(TelegramScheduledControlResponseService::class)->captureCallback($callback)['status'])->toBe('unmatched')
        ->and($delivery->responses()->count())->toBe(0);
});

it('keeps a Work bot reply-to question as a comment in the exact control occurrence', function () {
    config(['services.telegram.bot_token' => 'work-test-token']);
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true], 200)]);

    $this->postJson('/telegram/work-webhook/work-test', controlReplyPayload(
        $delivery, 'Курьеров на Комо не было ни в одной квартире, закрывать уборки?', 322,
    ))->assertOk();

    $response = $delivery->responses()->sole();
    expect($response->district_key)->toBe('como')
        ->and($response->response_kind)->toBe('comment')
        ->and($response->classification)->toBe('unclear')
        ->and($response->telegram_message_id)->toBe('322');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/botwork-test-token/editMessageText')
        && str_contains((string) $request['text'], 'Курьеров на Комо не было'));
});

it('retries source message synchronization without reapplying a Work control answer', function () {
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $edits = 0;
    Http::swap(new HttpFactory);
    Http::fake(function ($request) use (&$edits) {
        if (str_ends_with($request->url(), '/editMessageText')) {
            $edits++;

            return Http::response(['ok' => $edits > 1, 'result' => $edits > 1], $edits > 1 ? 200 : 500);
        }

        return Http::response(['ok' => true, 'result' => true], 200);
    });
    $callback = scheduledControlCallback($delivery, 'retry-sync', 'no');
    $callback['data'] = 'sc:'.$delivery->id.':como:no';

    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();

    expect($delivery->responses()->count())->toBe(1)
        ->and($edits)->toBe(2);
});

it('treats an unchanged Telegram edit on a duplicate click as already synchronized', function () {
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $edits = 0;
    Http::swap(new HttpFactory);
    Http::fake(function ($request) use (&$edits) {
        if (str_ends_with($request->url(), '/editMessageText')) {
            $edits++;

            return $edits === 1 ? Http::response(['ok' => true, 'result' => true], 200)
                : Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], 400);
        }

        return Http::response(['ok' => true, 'result' => true], 200);
    });
    $callback = scheduledControlCallback($delivery, 'duplicate-click', 'yes');
    $callback['data'] = 'sc:'.$delivery->id.':como:yes';
    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();

    $answers = Http::recorded()->pluck(0)->filter(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery'))
        ->map(fn ($request): string => $request['text'])->values()->all();
    expect($delivery->responses()->count())->toBe(1)
        ->and($answers)->toBe(['Ответ сохранён.', 'Ответ сохранён.']);
});

it('keeps the previous text when a Work bot reply is edited', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_completed', 'bot_type' => 'work']);
    $original = controlReplyPayload($delivery, 'Como — нет', 323);
    captureControlReply($original);
    $edited = $original;
    $edited['edited_message'] = $edited['message'];
    unset($edited['message']);
    $edited['edited_message']['text'] = 'Como — да';
    $edited['edited_message']['edit_date'] = $edited['edited_message']['date'] + 30;
    $stored = app(TelegramUpdateIngestService::class)->ingest($edited);
    app(TelegramScheduledControlResponseService::class)->capture($stored, $edited['edited_message']);

    $response = $delivery->responses()->sole();
    expect($response->text)->toBe('Como — да')
        ->and($response->response_history)->toHaveCount(1)
        ->and($response->response_history[0]['text'])->toBe('Como — нет');
});

it('queues the existing payment follow-up once all five district cleanings are confirmed after the threshold', function () {
    $this->travelTo(Carbon::parse('2026-10-01 19:00:00', 'Europe/Rome'));
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    $cleanings = controlDeliveryFixture(['control_type' => ScheduledControlTypes::CLOSING_CLEANINGS, 'bot_type' => 'work']);
    closingPaymentTemplateFor($cleanings);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true], 200)]);
    Bus::fake();

    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $index => $district) {
        $callback = scheduledControlCallback($cleanings, 'all-districts-'.$district, 'yes');
        $callback['data'] = 'sc:'.$cleanings->id.':'.$district.':yes';
        $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
        expect(TelegramScheduledMessageDelivery::where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())
            ->toBe($index === 4 ? 1 : 0);
    }

    expect(TelegramScheduledMessageDelivery::where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->sole()->bot_type)->toBe('work');
    Bus::assertDispatchedTimes(DeliverScheduledTelegramMessage::class, 1);
});

function closingPaymentTemplateFor(TelegramScheduledMessageDelivery $cleanings): TelegramScheduledMessage
{
    $topic = TelegramTopic::firstOrCreate(
        [
            'telegram_chat_id' => $cleanings->scheduledMessage->telegram_chat_record_id,
            'telegram_thread_id' => (string) $cleanings->message_thread_id,
        ],
        ['title' => 'Closing controls', 'is_enabled' => true],
    );
    $cleanings->scheduledMessage->update(['telegram_topic_record_id' => $topic->id]);

    return TelegramScheduledMessage::create([
        'name' => 'Closing payments', 'control_type' => ScheduledControlTypes::CLOSING_PAYMENTS,
        'telegram_chat_record_id' => $cleanings->scheduledMessage->telegram_chat_record_id,
        'telegram_topic_record_id' => $topic->id,
        'message' => 'Все доплаты за день произведены?', 'send_time' => '18:00:00',
        'weekdays' => [4], 'enabled' => true,
    ]);
}

function storeBackfillReply(TelegramScheduledMessageDelivery $delivery, int $id, array $overrides = []): void
{
    $payload = controlReplyPayload($delivery, 'Все готово', $id);
    $payload['message'] = array_replace($payload['message'], $overrides);
    app(TelegramUpdateIngestService::class)->ingest($payload);
}

function runScheduledControlBackfill(bool $dryRun = false, bool $apply = true): int
{
    return Artisan::call('telegram:scheduled-control-responses-backfill', array_filter([
        '--from' => '2026-10-01',
        '--to' => '2026-10-02',
        '--dry-run' => $dryRun ? true : null,
        '--apply' => ! $dryRun && $apply ? true : null,
    ], fn ($value) => $value !== null));
}

function fakeScheduledPollResponses(): ResponseSequence
{
    $factory = new HttpFactory;
    Http::swap($factory);

    return $factory->fakeSequence();
}

it('links an exact scheduled delivery reply and measures from actual send', function () {
    $delivery = controlDeliveryFixture();
    $response = captureControlReply(controlReplyPayload($delivery));
    expect($response->delivery_id)->toBe($delivery->id)
        ->and($response->response_latency_seconds)->toBe(360)
        ->and($response->classification)->toBe('confirmed');
});

it('lets a cleaner change a legacy closing answer without treating it as five district confirmations', function () {
    Bus::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    $cleanings = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'scheduled_for' => '2026-10-01 19:00:00', 'sent_at' => '2026-10-01 19:00:00', 'telegram_message_id' => 9901,
    ]);
    closingPaymentTemplateFor($cleanings);

    $processor = app(TelegramScheduledInboundProcessor::class);
    $noButton = scheduledControlCallback($cleanings, 'callback-no', 'no');
    unset($noButton['message']['message_thread_id']);
    $processor->process(['callback_query' => $noButton]);

    $answer = TelegramScheduledMessageResponse::query()->sole();
    expect($answer->text)->toBe('Нет')
        ->and($answer->classification)->toBe('problem')
        ->and($answer->telegram_message_record_id)->toBeNull()
        ->and($answer->telegram_callback_query_id)->toBe('callback-no')
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);

    $processor->process(['callback_query' => scheduledControlCallback($cleanings, 'callback-yes', 'yes')]);
    $answer->refresh();
    $processor->process(['callback_query' => scheduledControlCallback($cleanings, 'callback-yes-retry', 'yes')]);

    expect(TelegramScheduledMessageResponse::query()->count())->toBe(1)
        ->and($answer->text)->toBe('Да')
        ->and($answer->classification)->toBe('confirmed')
        ->and($answer->telegram_callback_query_id)->toBe('callback-yes')
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);

    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
});

it('stores the closing payment button answer as final and ignores later edits', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    $delivery = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_PAYMENTS,
        'scheduled_for' => '2026-10-01 19:00:00', 'sent_at' => '2026-10-01 19:05:00', 'telegram_message_id' => 9902,
    ]);
    $processor = app(TelegramScheduledInboundProcessor::class);

    $processor->process(['callback_query' => scheduledControlCallback($delivery, 'payment-no', 'no')]);
    $processor->process(['callback_query' => scheduledControlCallback($delivery, 'payment-yes', 'yes')]);

    $response = TelegramScheduledMessageResponse::query()->sole();
    expect($response->text)->toBe('Нет')
        ->and($response->classification)->toBe('problem')
        ->and($response->telegram_callback_query_id)->toBe('payment-no');
});

it('rejects closing control buttons from another chat or topic', function () {
    $delivery = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'telegram_message_id' => 9903,
    ]);
    $processor = app(TelegramScheduledInboundProcessor::class);
    $wrongChat = scheduledControlCallback($delivery, 'wrong-chat', 'no');
    $wrongChat['message']['chat']['id'] = -1999;
    $wrongTopic = scheduledControlCallback($delivery, 'wrong-topic', 'no');
    $wrongTopic['message']['message_thread_id'] = 99;

    expect($processor->process(['callback_query' => $wrongChat])['callback_status'])->toBe('unmatched')
        ->and($processor->process(['callback_query' => $wrongTopic])['callback_status'])->toBe('unmatched')
        ->and(TelegramScheduledMessageResponse::query()->count())->toBe(0);

    config(['services.telegram.scheduled_webhook_allowed_chat_ids' => ['-1002']]);
    expect($processor->process(['callback_query' => scheduledControlCallback($delivery, 'disallowed-chat', 'no')]))
        ->toMatchArray(['ok' => true, 'skipped' => 'chat_not_allowed'])
        ->and(TelegramScheduledMessageResponse::query()->count())->toBe(0);
});

it('does not treat an unassigned legacy text yes as five district confirmations', function () {
    Bus::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    $cleanings = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'scheduled_for' => '2026-10-01 19:00:00', 'sent_at' => '2026-10-01 19:00:00', 'telegram_message_id' => 9904,
    ]);
    closingPaymentTemplateFor($cleanings);
    $payload = controlReplyPayload($cleanings, 'Да', 9905, 701, '2026-10-01 19:10:00');

    app(TelegramScheduledInboundProcessor::class)->process($payload);

    expect(TelegramScheduledMessageResponse::query()->sole()->text)->toBe('Да')
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
});

it('lets the same duty user change a text no answer to yes from the original message buttons', function () {
    Bus::fake();
    config(['services.telegram.scheduled_bot_token' => 'scheduled-test-token']);
    $cleanings = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS,
        'scheduled_for' => '2026-10-01 19:00:00', 'sent_at' => '2026-10-01 19:00:00', 'telegram_message_id' => 9906,
    ]);
    closingPaymentTemplateFor($cleanings);
    $processor = app(TelegramScheduledInboundProcessor::class);
    $processor->process(controlReplyPayload($cleanings, 'Нет', 9907, 701, '2026-10-01 19:05:00'));
    $response = TelegramScheduledMessageResponse::query()->sole();
    $originalResponseId = $response->id;
    $originalMessageRecordId = $response->telegram_message_record_id;

    $processor->process(['callback_query' => scheduledControlCallback($cleanings, 'text-no-to-button-yes', 'yes')]);
    $response->refresh();

    expect(TelegramScheduledMessageResponse::query()->count())->toBe(1)
        ->and($response->id)->toBe($originalResponseId)
        ->and($response->telegram_message_record_id)->toBe($originalMessageRecordId)
        ->and($response->text)->toBe('Да')
        ->and($response->classification)->toBe('confirmed')
        ->and(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
});

it('captures explicit replies for future control types and keeps occurrences distinct', function () {
    $first = controlDeliveryFixture(['control_type' => 'couriers_finished']);
    $second = controlDeliveryFixture([
        'control_type' => 'couriers_finished', 'scheduled_for' => '2026-10-01 10:30:00',
        'sent_at' => '2026-10-01 10:35:00',
    ]);
    $third = controlDeliveryFixture(['control_type' => 'supplements_paid']);
    $processor = app(TelegramScheduledInboundProcessor::class);

    foreach ([
        [$first, 401, 701, '2026-10-01 09:41:00'],
        [$first, 402, 702, '2026-10-01 09:41:00'],
        [$second, 403, 703, '2026-10-01 10:41:00'],
        [$third, 404, 704, '2026-10-01 09:41:00'],
    ] as [$delivery, $replyId, $authorId, $at]) {
        $payload = controlReplyPayload($delivery, 'Ответ по контролю', $replyId, $authorId, $at);
        expect($processor->process($payload)['ok'])->toBeTrue();
    }

    $processor->process(controlReplyPayload($first, 'Ответ по контролю', 401, 701));
    $responses = TelegramScheduledMessageResponse::query()->orderBy('telegram_message_id')->get();

    expect($responses)->toHaveCount(4)
        ->and($responses->pluck('delivery_id')->all())->toBe([$first->id, $first->id, $second->id, $third->id])
        ->and($responses->first()->delivery->control_type)->toBe('couriers_finished')
        ->and($responses->first()->delivery->telegram_message_id)->toBe($first->telegram_message_id)
        ->and($responses->first()->delivery->scheduled_message_id)->toBe($first->scheduled_message_id)
        ->and($responses->first()->telegram_user_id)->toBe('701')
        ->and($responses->first()->user_id)->toBeNull()
        ->and($responses->first()->telegramMessage->raw['update_id'])->toBe(1401);
});

it('rejects explicit replies for a future control type outside its chat or thread', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'couriers_finished']);

    foreach ([
        ['chat' => ['id' => -1002, 'type' => 'supergroup']],
        ['message_thread_id' => 12],
        ['reply_to_message' => ['message_id' => 9999]],
    ] as $index => $change) {
        $payload = controlReplyPayload($delivery, 'Ответ по контролю', 410 + $index);
        $payload['message'] = array_replace($payload['message'], $change);
        expect(captureControlReply($payload))->toBeNull();
    }

    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('backfills only an exact reply to a supported sent control occurrence', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 201);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::sole()->delivery_id)->toBe($delivery->id);
});

it('rejects a matching reply id from the wrong chat', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 202, ['chat' => ['id' => -999, 'type' => 'supergroup']]);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Candidate replies found: 0');
});

it('rejects a matching reply id from the wrong thread when the delivery thread is known', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 203, ['message_thread_id' => 12]);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Unmatched/ambiguous: 1');
});

it('skips a reply whose delivery correlation is ambiguous', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    controlDeliveryFixture([
        'control_type' => 'schedule_checked',
        'telegram_message_id' => $delivery->telegram_message_id,
    ]);
    storeBackfillReply($delivery, 211);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Unmatched/ambiguous: 1');
});

it('ignores unrelated stored messages', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $payload = controlReplyPayload($delivery, 'Обычное сообщение', 204);
    unset($payload['message']['reply_to_message']);
    app(TelegramUpdateIngestService::class)->ingest($payload);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Candidate replies found: 0');
});

it('captures multiple valid replies to one control occurrence', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 205);
    storeBackfillReply($delivery, 206);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(2);
});

it('backfills no-reply responses through the same bounded window matcher', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $payload = controlReplyPayload($delivery, 'Да, всё проверено', 220, 101, '2026-10-01 10:05:00');
    unset($payload['message']['reply_to_message']);
    app(TelegramUpdateIngestService::class)->ingest($payload);

    expect(runScheduledControlBackfill(true))->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Would insert: 1');
    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::sole()->delivery_id)->toBe($delivery->id)
        ->and(TelegramScheduledMessageResponse::sole()->reply_to_message_id)->toBeNull();
});

it('counts ambiguous fallback candidates only for deliveries in the matching thread and window', function () {
    $previousWindow = controlDeliveryFixture([
        'control_type' => 'schedule_checked',
        'scheduled_for' => '2026-10-01 09:00:00',
        'sent_at' => '2026-10-01 09:00:00',
    ]);
    $otherThread = controlDeliveryFixture(['message_thread_id' => '12']);
    $delivery = controlDeliveryFixture([
        'scheduled_for' => '2026-10-01 10:00:00',
        'sent_at' => '2026-10-01 10:00:00',
    ]);
    $conflicting = controlDeliveryFixture([
        'control_type' => 'first_cleanings_finishing',
        'scheduled_for' => '2026-10-01 10:00:00',
        'sent_at' => '2026-10-01 10:00:00',
    ]);
    $payload = controlReplyPayload($delivery, 'Да, все начали', 222, 101, '2026-10-01 10:05:00');
    unset($payload['message']['reply_to_message']);
    app(TelegramUpdateIngestService::class)->ingest($payload);

    expect(runScheduledControlBackfill(true))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Deliveries scanned: 4', 'Candidate replies found: 1', 'Unmatched/ambiguous: 1', 'Would insert: 0');

    foreach ([[$previousWindow, 0], [$otherThread, 0], [$delivery, 1], [$conflicting, 1]] as [$occurrence, $expectedCount]) {
        expect($output)->toContain(sprintf('  #%d (%s, message %s): %d', $occurrence->id, $occurrence->chat_id, $occurrence->telegram_message_id, $expectedCount));
    }
    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('dry run reports recoverable replies without writing any database rows', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 207);
    $messagesBefore = TelegramMessage::count();
    $deliveriesBefore = TelegramScheduledMessageDelivery::count();

    expect(runScheduledControlBackfill(true))->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(TelegramMessage::count())->toBe($messagesBefore)
        ->and(TelegramScheduledMessageDelivery::count())->toBe($deliveriesBefore)
        ->and(Artisan::output())->toContain('Would insert: 1');
});

it('defaults to read-only unless apply is explicitly requested', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 210);

    expect(runScheduledControlBackfill(false, false))->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Would insert: 1');
});

it('is idempotent when the historical backfill is repeated', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    storeBackfillReply($delivery, 208);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);
    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1)
        ->and(Artisan::output())->toContain('Already captured: 1');
});

it('ignores unsupported and test control types', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'control_question']);
    storeBackfillReply($delivery, 209);

    expect(runScheduledControlBackfill())->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0)
        ->and(Artisan::output())->toContain('Deliveries scanned: 0');
});

it('does not capture a random chat message or unrelated reply', function () {
    $payload = controlReplyPayload(controlDeliveryFixture());
    unset($payload['message']['reply_to_message']);
    $payload['message']['text'] = 'Мы поговорим после обеда';
    expect(captureControlReply($payload))->toBeNull();
    $payload['message']['reply_to_message'] = ['message_id' => 555];
    expect(captureControlReply($payload))->toBeNull();
    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('deduplicates the same reply received by both bots', function () {
    $payload = controlReplyPayload(controlDeliveryFixture());
    $this->postJson('/telegram/work-webhook/work-test', $payload)->assertOk();
    $this->postJson(route('telegram.scheduled.webhook', ['secret' => 'scheduled-test']), $payload)->assertOk();
    expect(TelegramScheduledMessageResponse::count())->toBe(1);
    expect(TelegramMessage::count())->toBe(1);
    Http::assertNothingSent();
});

it('captures explicit and bounded replyless responses through the scheduled bot webhook and exposes them to the summary', function () {
    $explicitDelivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $explicit = controlReplyPayload($explicitDelivery, 'Да, всё проверено', 223);

    $this->postJson(route('telegram.scheduled.webhook', ['secret' => 'scheduled-test']), $explicit)->assertOk();
    $this->postJson(route('telegram.scheduled.webhook', ['secret' => 'scheduled-test']), $explicit)->assertOk();

    $fallbackDelivery = controlDeliveryFixture([
        'control_type' => 'couriers_completed',
        'scheduled_for' => '2026-10-01 10:30:00',
        'sent_at' => '2026-10-01 10:30:00',
        'telegram_message_id' => 9923,
    ]);
    $replyless = controlReplyPayload($fallbackDelivery, 'Да, все завершили', 224, 102, '2026-10-01 10:35:00');
    unset($replyless['message']['reply_to_message']);

    $this->postJson(route('telegram.scheduled.webhook', ['secret' => 'scheduled-test']), $replyless)->assertOk();

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $controls = collect($summary['controls'])->keyBy('control_type');

    expect(TelegramMessage::query()->whereIn('message_id', ['223', '224'])->count())->toBe(2)
        ->and(TelegramScheduledMessageResponse::count())->toBe(2)
        ->and(TelegramScheduledMessageResponse::query()->where('delivery_id', $explicitDelivery->id)->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::query()->where('delivery_id', $fallbackDelivery->id)->sole()->reply_to_message_id)->toBeNull()
        ->and($summary['totals']['response_messages'])->toBe(2)
        ->and($controls['schedule_checked']['responses'])->toHaveCount(1)
        ->and($controls['couriers_completed']['responses'])->toHaveCount(1);
});

it('isolates both Telegram chat and topic identifiers', function () {
    $payload = controlReplyPayload(controlDeliveryFixture());
    $payload['message']['chat']['id'] = -1002;
    expect(captureControlReply($payload))->toBeNull();
    $payload['message']['chat']['id'] = -1001;
    $payload['message']['message_thread_id'] = 12;
    expect(captureControlReply($payload))->toBeNull();
});

it('reuses the existing staff mapping and preserves Telegram identity', function () {
    DB::table('users')->insert(['id' => 77, 'name' => 'Test staff', 'telegram_id' => '101']);
    $response = captureControlReply(controlReplyPayload(controlDeliveryFixture()));
    expect($response->user_id)->toBe(77)->and($response->telegram_user_id)->toBe('101')
        ->and($response->author_name)->toBe('Test staff')->and($response->username)->toBe('test_staff');
});

it('classifies bounded Russian responses with negation taking precedence', function ($text, $expected) {
    expect(app(ScheduledControlResponseClassifier::class)->classify($text)['classification'])->toBe($expected);
})->with([
    ['Да', 'confirmed'], ['Да, всё проверено', 'confirmed'], ['Все начались', 'confirmed'], ['Готово', 'confirmed'], ['Проверено ✅', 'confirmed'],
    ['Все ок', 'confirmed'], ['Да, всё завершено', 'confirmed'], ['Все завершили', 'confirmed'],
    ['Нет, одна уборка еще не началась', 'problem'], ['Курьер не приехал', 'problem'],
    ['Маша опоздает минут на 20', 'problem'], ['опоздает на 15 минут', 'problem'],
    ['будет позже минут на 10', 'problem'], ['задержится примерно на 20 минут', 'problem'],
    ['Есть проблема с Via X', 'problem'], ['Не все завершили', 'problem'], ['Нет, не всё хорошо', 'problem'],
    ['4 из 5 начали', 'partial'], ['Почти все', 'partial'], ['Одна осталась', 'partial'], ['Все кроме Navigli', 'partial'],
    ['Сейчас уточню', 'unclear'], ['Не знаю', 'unclear'], ['?', 'unclear'], ['Проверяем', 'unclear'],
    ['Да, все начали, но курьер не приехал', 'problem'], ['Да, не все начали', 'problem'],
    ["🟢 Lambrate - да\n🟢 Navigli - да\n🟢 Lodi - да\n🟢 Certosa - да\n🟢 Como - да", 'confirmed'],
    ["🟢Lambrate да\n🔴Lambrate нет", 'problem'],
    ['Возможно всё хорошо', 'unclear'],
]);

it('parses district markers with inline and following operational details', function () {
    $interpreter = app(ScheduledControlResponseInterpreter::class);
    $inline = $interpreter->interpret('🔴 Como нет, клинер опоздала из-за поезда', 'first_cleanings_started');
    $following = $interpreter->interpret(
        "🔴 Lambrate нет\nSirtori в 13 начали квартира большая, закончим в 16/16:30\nSolferino начали только в 12:00",
        'first_cleanings_started',
    );

    expect($inline['status'])->toBe('problem')
        ->and($inline['districts'][0])->toMatchArray([
            'district' => 'Como', 'status' => 'problem', 'reason' => 'задержка',
            'details' => ['клинер опоздала из-за поезда'],
        ])
        ->and($following['districts'][0])->toMatchArray([
            'district' => 'Lambrate', 'status' => 'problem',
            'details' => [
                'Sirtori в 13 начали квартира большая, закончим в 16/16:30',
                'Solferino начали только в 12:00',
            ],
        ]);
});

it('aggregates all five district confirmations into one compact control line', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'first_cleanings_started']);
    $text = "🟢 Lambrate - да\n🟢 Navigli - да\n🟢 Lodi - да\n🟢 Certosa - да\n🟢 Como - да";
    $response = captureControlReply(controlReplyPayload($delivery, $text));
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $control = collect($summary['controls'])->firstWhere('control_type', 'first_cleanings_started');
    $rendered = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($response->classification)->toBe('confirmed')
        ->and($control['status'])->toBe('ok')
        ->and($control['district_results'])->toHaveCount(5)
        ->and($rendered)->toContain('🧹 TRIS — Контроль уборок · 01.10.2026', '✅ Все первые уборки начались')
        ->not->toContain('Lambrate да', 'Navigli да', 'Список ожидаемых участников не настроен')
        ->and(mb_strlen($rendered))->toBeLessThan(800);
});

it('merges district evidence from multiple supervisors and preserves no-payment details', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'extra_payments_completed', 'bot_type' => 'work']);
    captureControlReply(controlReplyPayload(
        $delivery,
        "🟢Lambrate да\nMonte Lungo за ожидание выезда гостей\n🟢Lodi не было\n🟢Como не было",
        801,
        101,
        '2026-10-01 10:00:00',
    ));
    captureControlReply(controlReplyPayload(
        $delivery,
        "🟢 Certosa да\nPalazzi — ожидание гостя",
        802,
        102,
        '2026-10-01 10:01:00',
    ));
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $control = collect($summary['controls'])->firstWhere('control_type', 'extra_payments_completed');
    $rendered = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($control['district_results'])->toHaveCount(4)
        ->and(collect($control['district_results'])->firstWhere('district', 'Lambrate')['details'])
        ->toContain('Monte Lungo за ожидание выезда гостей')
        ->and(collect($control['district_results'])->firstWhere('district', 'Lodi')['status'])->toBe('no_data')
        ->and($rendered)->toContain('💶 Доплаты: Lambrate — Monte Lungo за ожидание выезда гостей')
        ->toContain('доплат не было: Lodi и Como')
        ->not->toContain('⚠️ Доплаты');
});

it('keeps partial district coverage explicit and does not guess from unassigned details', function () {
    $partial = app(ScheduledControlResponseInterpreter::class)->interpret('🟢 Lambrate да', 'couriers_completed');
    $unassigned = app(ScheduledControlResponseInterpreter::class)->interpret(
        "Palazzi начали в 11:00\nSirtori закончили в 16:00",
        'first_cleanings_started',
    );

    expect($partial['status'])->toBe('partial')
        ->and($partial['districts'][0]['district'])->toBe('Lambrate')
        ->and($unassigned['districts'])->toBe([])
        ->and($unassigned['unassigned_details'])->toBe([
            'Palazzi начали в 11:00', 'Sirtori закончили в 16:00',
        ]);
});

it('applies a later same-supervisor correction but surfaces conflicting supervisors', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'first_cleanings_started']);
    captureControlReply(controlReplyPayload($delivery, '🔴Lambrate нет', 803, 101, '2026-10-01 10:00:00'));
    captureControlReply(controlReplyPayload($delivery, '🟢Lambrate да', 804, 101, '2026-10-01 10:05:00'));
    $corrected = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $correctedDistrict = collect($corrected['controls'])->firstWhere('control_type', 'first_cleanings_started')['district_results'][0];

    expect($correctedDistrict['status'])->toBe('ok')
        ->and($correctedDistrict['history'])->toBe(['problem', 'ok']);

    TelegramScheduledMessageResponse::query()->delete();
    captureControlReply(controlReplyPayload($delivery, '🔴Lambrate нет', 805, 101, '2026-10-01 10:10:00'));
    captureControlReply(controlReplyPayload($delivery, '🟢Lambrate да', 806, 102, '2026-10-01 10:11:00'));
    $conflicted = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $conflictDistrict = collect($conflicted['controls'])->firstWhere('control_type', 'first_cleanings_started')['district_results'][0];

    expect($conflictDistrict['status'])->toBe('conflict')
        ->and(app(TelegramScheduledControlSummaryFormatter::class)->format($conflicted))
        ->toContain('конфликтующие статусы — Lambrate');
});

it('preserves later replies but uses each responders first reply for median latency', function () {
    $delivery = controlDeliveryFixture();
    captureControlReply(controlReplyPayload($delivery, 'Да', 100, 101, '2026-10-01 09:36:00'));
    captureControlReply(controlReplyPayload($delivery, 'Курьер не приехал', 101, 101, '2026-10-01 09:50:00'));
    captureControlReply(controlReplyPayload($delivery, 'Проверено', 102, 102, '2026-10-01 09:38:00'));
    $stats = app(TelegramScheduledControlStatistics::class)->delivery($delivery->fresh('responses'));
    expect($stats['response_count'])->toBe(3)->and($stats['unique_responder_count'])->toBe(2)
        ->and($stats['median_response_latency_seconds'])->toBe(120.0)->and($stats['result'])->toBe('problem');
});

it('exposes the distinct closing cleanings and payments control types', function () {
    expect(ScheduledControlTypes::options())->toEqual([
        'schedule_checked' => 'Время уборок и заметки проверены',
        'first_cleanings_started' => 'Все первые уборки начались',
        'first_cleanings_finishing' => 'Первые уборки подходят к завершению',
        'second_cleanings_finishing' => 'Вторые уборки подходят к завершению',
        'cleanings_completed' => 'Все уборки завершены',
        'couriers_completed' => 'Курьеры завершили все доставки',
        'extra_payments_completed' => 'Все доплаты произведены',
    ]);
});

it('renders only the seven canonical control types and describes zero captured data clearly', function () {
    foreach (array_keys(ScheduledControlTypes::LABELS) as $controlType) {
        controlDeliveryFixture(['control_type' => $controlType]);
    }
    controlDeliveryFixture(['control_type' => 'control_question']);

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($summary['controls'])->toHaveCount(7)
        ->and(collect($summary['controls'])->pluck('control_type')->all())->toEqual(array_keys(ScheduledControlTypes::LABELS))
        ->and($text)->toContain('График и заметки: нет данных', 'Доплаты: нет данных')
        ->and(substr_count($text, 'Ответы на контрольные сообщения за этот день не зафиксированы.'))->toBe(1);

    expect($text)->not->toContain('control_question');
    expect($text)->not->toContain('подтверждений нет');
    expect($text)->not->toContain('Список ожидаемых участников не настроен');
});

it('keeps an explicit reply attached to its delivery even after a newer control starts', function () {
    $original = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    controlDeliveryFixture([
        'control_type' => 'first_cleanings_started',
        'scheduled_for' => '2026-10-01 10:00:00',
        'sent_at' => '2026-10-01 10:00:00',
        'telegram_message_id' => 9910,
    ]);
    $payload = controlReplyPayload($original, 'Да, всё проверено', 212, 101, '2026-10-01 10:05:00');

    $stored = app(TelegramUpdateIngestService::class)->ingest($payload);
    $response = app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']);

    expect($response->delivery_id)->toBe($original->id);
});

it('uses a plausible no-reply response only inside its unique chat and thread window', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $payload = controlReplyPayload($delivery, 'Да, всё проверено', 213, 101, '2026-10-01 10:05:00');
    unset($payload['message']['reply_to_message']);
    $stored = app(TelegramUpdateIngestService::class)->ingest($payload);

    $response = app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']);

    expect($response)->not->toBeNull()
        ->and($response->delivery_id)->toBe($delivery->id)
        ->and($response->reply_to_message_id)->toBeNull()
        ->and($response->classification)->toBe('confirmed');
});

it('rejects no-reply fallback from another chat or forum thread', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);

    foreach ([
        ['chat' => ['id' => -1002, 'type' => 'supergroup']],
        ['message_thread_id' => 12],
    ] as $index => $overrides) {
        $payload = controlReplyPayload($delivery, 'Да, всё проверено', 300 + $index, 101, '2026-10-01 10:05:00');
        unset($payload['message']['reply_to_message']);
        $payload['message'] = array_replace($payload['message'], $overrides);
        $stored = app(TelegramUpdateIngestService::class)->ingest($payload);

        expect(app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']))->toBeNull();
    }

    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('does not fall back before a control delivery or after the next control starts', function (string $at, bool $withNext) {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $next = $withNext ? controlDeliveryFixture([
        'control_type' => 'first_cleanings_started',
        'scheduled_for' => '2026-10-01 10:30:00',
        'sent_at' => '2026-10-01 10:30:00',
        'telegram_message_id' => 9911,
    ]) : null;
    $payload = controlReplyPayload($delivery, 'Да, всё проверено', 214, 101, $at);
    unset($payload['message']['reply_to_message']);
    $stored = app(TelegramUpdateIngestService::class)->ingest($payload);

    $response = app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']);

    expect($response?->delivery_id)->not->toBe($delivery->id);
    if ($next && $at === '2026-10-01 10:35:00') {
        expect($response?->delivery_id)->toBe($next->id);
    } else {
        expect($response)->toBeNull();
    }
})->with([
    ['2026-10-01 09:34:00', false],
    ['2026-10-01 10:35:00', true],
]);

it('skips ambiguous fallback windows, bot messages, and unrelated conversation', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    controlDeliveryFixture([
        'control_type' => 'first_cleanings_started',
        'scheduled_for' => '2026-10-01 09:30:00',
        'sent_at' => $delivery->sent_at,
        'telegram_message_id' => 9912,
    ]);
    foreach ([
        ['Да, всё проверено', 215, ['from' => ['id' => 101, 'is_bot' => false]]],
        ['Да, всё проверено', 216, ['from' => ['id' => 102, 'is_bot' => true]]],
        ['Мы поговорим после обеда', 217, ['from' => ['id' => 103, 'is_bot' => false]]],
    ] as [$text, $id, $from]) {
        $payload = controlReplyPayload($delivery, $text, $id, 101, '2026-10-01 10:05:00');
        unset($payload['message']['reply_to_message']);
        $payload['message']['from'] = $from;
        $stored = app(TelegramUpdateIngestService::class)->ingest($payload);
        expect(app(TelegramScheduledControlResponseService::class)->capture($stored, $payload['message']))->toBeNull();
    }

    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('does not attach a late replyless message to the last delivery', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $payload = controlReplyPayload($delivery, 'Да, всё проверено', 221, 101, '2026-10-01 10:36:00');
    unset($payload['message']['reply_to_message']);

    expect(captureControlReply($payload))->toBeNull()
        ->and(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('extracts partial district exceptions, delay and missing-key reasons from response evidence', function ($text, $expected) {
    expect(app(ScheduledControlResponseInterpreter::class)->interpret($text))->toMatchArray($expected);
})->with([
    ['Все начали', ['status' => 'ok', 'district' => null, 'delay_minutes' => null, 'reason' => null]],
    ['Все начали кроме Комо', ['status' => 'partial', 'district' => 'Como']],
    ['Маша опоздает минут на 20', ['status' => 'problem', 'delay_minutes' => 20, 'reason' => 'задержка']],
    ['опоздает на 15 минут', ['status' => 'problem', 'delay_minutes' => 15, 'reason' => 'задержка']],
    ['Маша задержится 20 минут', ['status' => 'problem', 'delay_minutes' => 20, 'reason' => 'задержка']],
    ['опоздает 15 мин', ['status' => 'problem', 'delay_minutes' => 15, 'reason' => 'задержка']],
    ['Купит 20 полотенец', ['status' => 'unknown', 'delay_minutes' => null, 'reason' => null]],
    ['будет позже минут на 10', ['status' => 'problem', 'delay_minutes' => 10, 'reason' => 'задержка']],
    ['задержится примерно на 20 минут', ['status' => 'problem', 'delay_minutes' => 20, 'reason' => 'задержка']],
    ['Там нет ключей', ['status' => 'problem', 'reason' => 'нет ключей']],
    ['Да', ['status' => 'ok']],
    ['Сейчас проверю?', ['status' => 'unknown']],
]);

it('renders aggregate control points and exceptions without inventing expected respondents', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'first_cleanings_started']);
    captureControlReply(controlReplyPayload($delivery, 'Маша опоздает минут на 20'));
    controlDeliveryFixture(['control_type' => 'couriers_completed', 'scheduled_for' => '2026-10-01 10:00:00', 'telegram_message_id' => 9999]);

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($summary['controls'])->toHaveCount(2)
        ->and($summary['exceptions'])->toHaveCount(1)
        ->and($summary['exceptions'][0]['delay_minutes'])->toBe(20)
        ->and($summary['expected_responders'])->toBeNull()
        ->and($summary['no_response_available'])->toBeFalse()
        ->and($text)->toContain('🧹 TRIS — Контроль уборок · 01.10.2026', '⚠️ Первые уборки: Маша опоздает минут на 20')
        ->not->toContain('Отклонения', 'не рассчитывается', 'Контролей:', 'Медиана', 'По типам');
});

it('exposes the requested read-only preview command and a compact empty state', function () {
    expect(Artisan::call('telegram:control-summary-preview', ['--date' => '2026-10-01']))->toBe(0)
        ->and(Artisan::output())->toContain('✅ Контрольных сообщений за день не было.')
        ->not->toContain('Нет ответа');
});

it('counts unclear responses as responded', function () {
    captureControlReply(controlReplyPayload(controlDeliveryFixture(), 'Сейчас уточню'));
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals']['responded'])->toBe(1)->and($summary['totals']['unclear'])->toBe(1)
        ->and($summary['totals']['no_response'])->toBe(0)->and($summary['expected_responders'])->toBeNull();
});

it('computes pending and no response from the actual send window', function () {
    $recent = controlDeliveryFixture(['sent_at' => '2026-10-01 11:30:00']);
    $expired = controlDeliveryFixture();
    $stats = app(TelegramScheduledControlStatistics::class);
    expect($stats->delivery($recent)['result'])->toBe('pending');
    expect($stats->delivery($expired)['result'])->toBe('no_response');
    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('does not call failed delivery an unanswered staff control', function () {
    $delivery = controlDeliveryFixture(['status' => 'failed', 'sent_at' => null, 'telegram_message_id' => null]);
    expect(app(TelegramScheduledControlStatistics::class)->delivery($delivery)['result'])->toBe('pending');
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals']['not_delivered'])->toBe(1)->and($summary['totals']['no_response'])->toBe(0);
});

it('reports a due control with no recorded delivery as not delivered', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome'));
    $missing = controlDeliveryFixture();
    $missing->delete();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($summary['totals'])->toMatchArray([
        'controls' => 0, 'expected' => 1, 'sent' => 0, 'responded' => 0,
        'not_delivered' => 1, 'no_response' => 0, 'response_rate_percent' => null,
    ])->and($summary['missing_deliveries'])->toHaveCount(1)
        ->and($summary['missing_deliveries'][0]['scheduled_message_id'])->toBe($missing->scheduled_message_id)
        ->and($summary['deliveries'])->toBe([])
        ->and($text)->toContain('отправка не зафиксирована')
        ->not->toContain('Контролей:', 'Не доставлено');
});

it('does not duplicate an expected occurrence when its sent delivery exists', function () {
    controlDeliveryFixture();

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');

    expect($summary['totals'])->toMatchArray([
        'controls' => 1, 'expected' => 1, 'sent' => 1, 'not_delivered' => 0,
    ])->and($summary['missing_deliveries'])->toBe([]);
});

it('expects closing cleanings at its configured time and excludes the payment follow-up', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome'));
    $chat = TelegramChat::firstOrCreate(
        ['telegram_chat_id' => '-1001'],
        ['title' => 'Test control chat', 'type' => 'supergroup', 'is_enabled' => true],
    );
    foreach ([
        [ScheduledControlTypes::CLOSING_CLEANINGS, '17:30:00'],
        [ScheduledControlTypes::CLOSING_PAYMENTS, '18:00:00'],
    ] as [$type, $time]) {
        TelegramScheduledMessage::create([
            'name' => $type, 'control_type' => $type, 'telegram_chat_record_id' => $chat->id,
            'message' => 'Control?', 'send_time' => $time, 'weekdays' => [4], 'enabled' => true,
        ]);
    }
    $this->travelTo(Carbon::parse('2026-10-01 22:30:00', 'Europe/Rome'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');

    expect($summary['missing_deliveries'])->toHaveCount(1)
        ->and($summary['missing_deliveries'][0]['control_type'])->toBe(ScheduledControlTypes::CLOSING_CLEANINGS)
        ->and($summary['missing_deliveries'][0]['scheduled_for'])->toContain('T17:30:00');
});

it('does not invent a missing occurrence when a filtered delivery already exists for that control and day', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome'));
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $delivery->scheduledMessage->update(['control_type' => 'couriers_completed']);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01', controlType: 'couriers_completed');

    expect($summary['totals'])->toMatchArray(['expected' => 0, 'not_delivered' => 0])
        ->and($summary['missing_deliveries'])->toBe([]);
});

it('keeps response rate based on sent occurrences when another due control has no delivery', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome'));
    $sent = controlDeliveryFixture();
    $missing = controlDeliveryFixture(['control_type' => 'couriers_completed']);
    $missing->delete();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    captureControlReply(controlReplyPayload($sent));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');

    expect($summary['totals'])->toMatchArray([
        'controls' => 1, 'expected' => 2, 'sent' => 1, 'responded' => 1,
        'response_rate_percent' => 100, 'not_delivered' => 1, 'no_response' => 0,
    ])->and($summary['missing_deliveries'])->toHaveCount(1);
});

it('excludes disabled and non-recurring controls from missing delivery expectations', function () {
    $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'Europe/Rome'));
    $disabled = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $disabled->scheduledMessage->update(['enabled' => false]);
    $disabled->delete();

    $wrongDay = controlDeliveryFixture(['control_type' => 'couriers_completed']);
    $wrongDay->scheduledMessage->update(['weekdays' => [5]]);
    $wrongDay->delete();

    $later = controlDeliveryFixture(['control_type' => 'extra_payments_completed']);
    $later->scheduledMessage->update(['send_time' => '17:00:00']);
    $later->delete();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');

    expect($summary['totals'])->toMatchArray([
        'expected' => 0, 'sent' => 0, 'not_delivered' => 0,
    ])->and($summary['missing_deliveries'])->toBe([]);
});

it('builds daily totals and a human summary without a fabricated denominator', function () {
    $this->travelTo(Carbon::parse('2026-10-01 23:00:00', 'Europe/Rome'));
    captureControlReply(controlReplyPayload(controlDeliveryFixture(), 'Курьер не приехал'));
    controlDeliveryFixture();
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals'])->toMatchArray(['controls' => 2, 'responded' => 1, 'problem' => 1, 'no_response' => 1]);
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);
    expect($text)->toContain('🧹 TRIS — Контроль уборок', '01.10.2026', 'Курьер не приехал')
        ->not->toContain('не рассчитывается', 'Контролей:', 'Ответы:', 'Медиана', 'По типам');
    expect(mb_strlen(html_entity_decode($text)))->toBeLessThan(4096);
});

it('aggregates first qualifying replies once per sent occurrence across generic control types', function () {
    $this->travelTo(Carbon::parse('2026-10-01 23:00:00', 'Europe/Rome'));
    $first = controlDeliveryFixture(['control_type' => 'couriers_finished']);
    $second = controlDeliveryFixture([
        'control_type' => 'supplements_paid', 'scheduled_for' => '2026-10-01 10:30:00',
        'sent_at' => '2026-10-01 10:35:00',
    ]);
    controlDeliveryFixture([
        'control_type' => 'couriers_finished', 'scheduled_for' => '2026-10-01 11:30:00',
        'sent_at' => '2026-10-01 11:35:00',
    ]);
    controlDeliveryFixture(['control_type' => 'supplements_paid', 'status' => 'failed', 'sent_at' => null, 'telegram_message_id' => null]);

    captureControlReply(controlReplyPayload($first, 'Да', 501, 101, '2026-10-01 09:39:00'));
    captureControlReply(controlReplyPayload($first, 'Курьер не приехал', 502, 101, '2026-10-01 09:50:00'));
    captureControlReply(controlReplyPayload($second, 'Да', 503, 102, '2026-10-01 10:40:00'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $totals = $summary['totals'];
    $types = collect($summary['controls'])->keyBy('control_type');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($totals)->toMatchArray([
        'controls' => 4, 'expected' => 4, 'sent' => 3, 'responded' => 2, 'response_rate_percent' => 67,
        'median_response_latency_seconds' => 270.0, 'problem' => 1, 'confirmed' => 1,
        'partial' => 0, 'unclear' => 0, 'no_response' => 1, 'not_delivered' => 1,
        'response_messages' => 3,
    ])
        ->and($types['couriers_finished']['statistics']['responded'])->toBe(1)
        ->and($types['couriers_finished']['statistics']['sent'])->toBe(2)
        ->and($types['supplements_paid']['statistics']['responded'])->toBe(1)
        ->and($types['supplements_paid']['statistics']['sent'])->toBe(1)
        ->and($text)->toContain('Курьер не приехал', '✅ Начало уборок')
        ->not->toContain('Контролей:', 'Ответы:', 'Медиана', 'По типам', 'couriers_finished', 'supplements_paid');
});

it('uses the daily cutoff for unanswered controls and excludes replies or sends after it', function () {
    $sent = controlDeliveryFixture(['control_type' => 'couriers_finished']);
    controlDeliveryFixture([
        'control_type' => 'supplements_paid', 'scheduled_for' => '2026-10-01 21:55:00',
        'sent_at' => '2026-10-01 22:10:00',
    ]);
    captureControlReply(controlReplyPayload($sent, 'Да', 504, 101, '2026-10-01 22:30:00'));

    $beforeCutoff = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($beforeCutoff['totals'])->toMatchArray(['sent' => 1, 'responded' => 0, 'pending' => 0, 'no_response' => 1, 'partial' => 0]);

    $this->travelTo(Carbon::parse('2026-10-01 23:00:00', 'Europe/Rome'));
    $afterCutoff = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($afterCutoff['totals'])->toMatchArray(['sent' => 1, 'responded' => 0, 'pending' => 0, 'no_response' => 1])
        ->and($afterCutoff['totals']['median_response_latency_seconds'])->toBeNull()
        ->and($afterCutoff['deliveries'][0]['interpreted_responses'])->toBeEmpty();
});

it('sends a concise human exception for one missing district while retaining statistics in JSON', function () {
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    captureControlReply(controlReplyPayload($delivery, "🟢 Lambrate - да\n🟢 Navigli - да\n🟢 Lodi - да\n🟢 Certosa - да"));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);
    expect($text)->toContain('⚠️ График и заметки: нет подтверждения по Como.')
        ->not->toContain('подтверждены: Lambrate', 'Контролей:', 'Ответы:', 'Медиана', 'По типам', 'schedule_checked');

    expect(app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01')['queued'])->toBe(1);
    expect(TelegramScheduledControlSummaryDelivery::query()->sole()->text)->toBe($text);
    expect(Artisan::call('telegram:scheduled-controls-summary-send', ['--date' => '2026-10-01', '--dry-run' => true, '--json' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['totals'])->toMatchArray(['response_rate_percent' => 100, 'median_response_latency_seconds' => 360.0])
        ->and($json['controls'][0]['control_type'])->toBe('schedule_checked')
        ->and($json['controls'][0]['statistics']['responded'])->toBe(1);
    Http::assertNothingSent();
});

it('counts due unanswered sent controls by occurrence without making them partial', function () {
    foreach (['first_cleanings_finishing', 'second_cleanings_finishing', 'couriers_completed', 'extra_payments_completed'] as $type) {
        controlDeliveryFixture(['control_type' => $type]);
    }
    $this->travelTo(Carbon::parse('2026-10-01 21:00:00', 'Europe/Rome'));

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals'])->toMatchArray([
        'sent' => 4, 'responded' => 0, 'no_response' => 4, 'partial' => 0, 'unclear' => 0,
    ])->and(collect($summary['deliveries'])->pluck('result')->all())->toBe(array_fill(0, 4, 'no_response'));
});

it('merges a confirmed first-cleaning follow-up only for the same apartment and responder', function () {
    if (! Schema::connection('sqlite')->hasTable('apartments')) {
        Schema::connection('sqlite')->create('apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
    DB::table('apartments')->updateOrInsert(['id' => 77], ['name' => 'Via Test 77']);

    $first = controlDeliveryFixture(['control_type' => 'first_cleanings_started']);
    captureControlReply(controlReplyPayload($first, 'Маша опоздает минут на 20', 701, 101));
    TelegramTopic::query()->where('telegram_thread_id', '11')->update(['apartment_id' => 77]);

    $later = controlDeliveryFixture([
        'control_type' => 'first_cleanings_finishing', 'scheduled_for' => '2026-10-01 11:00:00',
        'sent_at' => '2026-10-01 11:00:00', 'telegram_message_id' => 9901,
    ]);
    captureControlReply(controlReplyPayload($later, 'Все начали', 702, 101, '2026-10-01 11:05:00'));
    $merged = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');

    expect($merged['exceptions'])->toHaveCount(1)
        ->and($merged['exceptions'][0]['resolved_later'])->toBeTrue()
        ->and($merged['exceptions'][0]['history'])->toHaveCount(2)
        ->and($merged['exceptions'][0]['history'][0]['source_message_id'])->toBe('701')
        ->and($merged['exceptions'][0]['history'][1]['source_message_id'])->toBe('702')
        ->and($merged['exceptions'][0]['apartment'])->toBe('Via Test 77');
});

it('exposes JSON preview and send dry run without jobs or transport', function () {
    controlDeliveryFixture();
    expect(Artisan::call('telegram:scheduled-controls-summary', ['--date' => '2026-10-01', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['totals']['controls'])->toBe(1);
    expect(Artisan::call('telegram:scheduled-controls-summary-send', ['--date' => '2026-10-01', '--dry-run' => true, '--json' => true]))->toBe(0);
    expect(TelegramScheduledControlSummaryDelivery::count())->toBe(0);
    Bus::assertNotDispatched(DeliverScheduledControlSummary::class);
    Http::assertNothingSent();
});

it('reserves only one summary per date and destination even after responses change', function () {
    stubControlSummaryFormatter();
    $delivery = controlDeliveryFixture();
    $sender = app(TelegramScheduledControlSummaryDeliveryService::class);
    expect($sender->queue('2026-10-01')['queued'])->toBe(1);
    captureControlReply(controlReplyPayload($delivery));
    expect($sender->queue('2026-10-01')['queued'])->toBe(0);
    expect(TelegramScheduledControlSummaryDelivery::count())->toBe(1);
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);
});

it('keeps ingestion and existing observation available with main auto replies disabled', function () {
    config(['services.telegram.operational_observer_enabled' => true]);
    Queue::fake();
    $this->postJson('/telegram/work-webhook/work-test', controlReplyPayload(controlDeliveryFixture()))->assertOk();
    expect(TelegramScheduledMessageResponse::count())->toBe(1);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class);
    Http::assertNothingSent();
});

it('persists and captures a control-topic message outside the work-chat allowlist exactly once', function () {
    config(['services.telegram.work_allowed_chat_ids' => ['-1002'], 'services.telegram.operational_chat_ids' => ['-1002'], 'services.telegram.operational_observer_enabled' => true]);
    Queue::fake();
    $delivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $payload = controlReplyPayload($delivery, 'Да, всё проверено', 218);

    $this->postJson('/telegram/work-webhook/work-test', $payload)->assertOk();

    expect(TelegramMessage::query()->where('message_id', '218')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);
    Queue::assertNotPushed(ProcessTelegramOperationalMessage::class);
});

it('runs both operational observation and control capture for a shared source destination', function () {
    config([
        'services.telegram.work_allowed_chat_ids' => ['-1002'],
        'services.telegram.operational_chat_ids' => ['-1001'],
        'services.telegram.operational_observer_enabled' => true,
    ]);
    Queue::fake();
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да, всё проверено', 220);

    $this->postJson('/telegram/work-webhook/work-test', $payload)->assertOk();

    expect(TelegramMessage::query()->where('message_id', '220')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 1);
});

it('invokes scheduled response capture once after out-of-allowlist persistence', function () {
    config(['services.telegram.work_allowed_chat_ids' => ['-1002'], 'services.telegram.operational_chat_ids' => ['-1002']]);
    $service = Mockery::mock(TelegramScheduledControlResponseService::class);
    $service->shouldReceive('isSupportedControlDestination')->once()->andReturn(true);
    $service->shouldReceive('capture')->once()->andReturnNull();
    $this->app->instance(TelegramScheduledControlResponseService::class, $service);
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да', 219);

    $this->postJson('/telegram/work-webhook/work-test', $payload)->assertOk();

    expect(TelegramMessage::query()->where('message_id', '219')->count())->toBe(1);
});

it('accepts an exact future-control reply through the shared work webhook', function () {
    config(['services.telegram.work_allowed_chat_ids' => ['-1002'], 'services.telegram.operational_chat_ids' => ['-1002']]);
    $delivery = controlDeliveryFixture(['control_type' => 'supplements_paid']);

    $this->postJson('/telegram/work-webhook/work-test', controlReplyPayload($delivery, 'Доплаты выполнены', 431))
        ->assertOk();

    expect(TelegramScheduledMessageResponse::sole()->delivery_id)->toBe($delivery->id)
        ->and(TelegramScheduledMessageResponse::sole()->delivery->control_type)->toBe('supplements_paid');
});

it('keeps callback queries out of response capture', function () {
    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => ['id' => 'fixture', 'data' => 'unknown:callback']])->assertOk();
    expect(TelegramScheduledMessageResponse::count())->toBe(0);
    $this->postJson(route('telegram.scheduled.webhook', ['secret' => 'scheduled-test']), ['callback_query' => ['id' => 'fixture']])->assertOk();
    expect(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('polls scheduled updates in order through the shared inbound path and confirms the successful batch', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $explicitDelivery = controlDeliveryFixture(['control_type' => 'schedule_checked']);
    $fallbackDelivery = controlDeliveryFixture([
        'control_type' => 'couriers_completed',
        'scheduled_for' => '2026-10-01 10:30:00',
        'sent_at' => '2026-10-01 10:30:00',
        'telegram_message_id' => 9951,
    ]);
    $explicit = controlReplyPayload($explicitDelivery, 'Да, всё проверено', 251);
    $explicit['update_id'] = 501;
    $replyless = controlReplyPayload($fallbackDelivery, 'Да, все завершили', 252, 102, '2026-10-01 10:35:00');
    $replyless['update_id'] = 502;
    unset($replyless['message']['reply_to_message']);

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$replyless, $explicit]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramScheduledMessageResponse::query()->orderBy('id')->pluck('telegram_message_id')->all())->toBe(['251', '252']);

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals']['response_messages'])->toBe(2)
        ->and(collect($summary['controls'])->keyBy('control_type')['schedule_checked']['responses'])->toHaveCount(1)
        ->and(collect($summary['controls'])->keyBy('control_type')['couriers_completed']['responses'])->toHaveCount(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['allowed_updates'])->toBe(['message', 'edited_message', 'callback_query'])
        ->and($requests[0]['limit'])->toBe(50)
        ->and($requests[0]->data())->not->toHaveKey('offset')
        ->and($requests[1]['offset'])->toBe(503);
});

it('acknowledges a successful update with the next offset request', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да, проверено', 254);
    $payload['update_id'] = 504;

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[1]['offset'])->toBe(505)
        ->and($requests[1]['limit'])->toBe(1);
});

it('keeps repeated polled updates idempotent', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $payload = controlReplyPayload(controlDeliveryFixture(), 'Да, все начали', 253);
    $payload['update_id'] = 503;

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []])
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '253')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);
});

it('does not acknowledge a batch when its middle update fails and retries failed and later updates', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $updates = collect([12, 10, 11])->map(fn (int $id): array => ['update_id' => $id, 'message' => ['message_id' => $id]])->all();
    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => $updates])
        ->push(['ok' => true, 'result' => $updates])
        ->push(['ok' => true, 'result' => []]);

    $failedOnce = false;
    $seen = [];
    $processor = Mockery::mock(TelegramScheduledInboundProcessor::class);
    $processor->shouldReceive('process')->times(5)->andReturnUsing(function (array $update) use (&$failedOnce, &$seen): array {
        $seen[] = $update['update_id'];

        if ($update['update_id'] === 11 && ! $failedOnce) {
            $failedOnce = true;
            throw new RuntimeException('fixture failure');
        }

        return ['ok' => true];
    });
    $this->app->instance(TelegramScheduledInboundProcessor::class, $processor);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('update 11 failed', 'remain unconfirmed');

    expect(Http::recorded())->toHaveCount(1)
        ->and(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and($seen)->toBe([10, 11, 10, 11, 12]);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(3)
        ->and($requests[2]['offset'])->toBe(13);
});

it('ignores malformed scheduled callback updates without losing a valid message update', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да, проверено', 255);
    $payload['update_id'] = 505;
    $irrelevant = ['update_id' => 504, 'callback_query' => ['id' => 'old-pending-update']];

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$payload, $irrelevant]])
        ->push(['ok' => true, 'result' => true])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '255')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(3)
        ->and($requests[0]['allowed_updates'])->toBe(['message', 'edited_message', 'callback_query'])
        ->and($requests[2]['allowed_updates'])->toBe(['message', 'edited_message', 'callback_query'])
        ->and($requests[2]['offset'])->toBe(506);
});

it('returns cleanly for an empty poll without issuing a confirmation request', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    fakeScheduledPollResponses()->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(Artisan::output())->toContain('0 update(s) processed')
        ->and(Http::recorded())->toHaveCount(1);
});

it('fails a Telegram polling API error without processing updates or advancing the offset', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    fakeScheduledPollResponses()->push(['ok' => false, 'description' => 'Conflict'], 409);
    $processor = Mockery::mock(TelegramScheduledInboundProcessor::class);
    $processor->shouldNotReceive('process');
    $this->app->instance(TelegramScheduledInboundProcessor::class, $processor);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('pending updates remain available for retry')
        ->and(Http::recorded())->toHaveCount(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(0);
});

it('retries an already processed batch if its offset acknowledgement request fails', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да, проверено', 256);
    $payload['update_id'] = 506;

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => false, 'description' => 'Temporary failure'], 502)
        ->push(['ok' => true, 'result' => [$payload]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(1)
        ->and(Artisan::output())->toContain('pending updates remain available for retry')
        ->and(TelegramMessage::query()->where('message_id', '256')->count())->toBe(1)
        ->and(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '256')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(4)
        ->and($requests[1]['offset'])->toBe(507)
        ->and($requests[3]['offset'])->toBe(507);
});

it('does not automatically retry a summary whose Telegram receipt is uncertain', function () {
    stubControlSummaryFormatter();
    controlDeliveryFixture();
    app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01');
    $receipt = TelegramScheduledControlSummaryDelivery::sole();
    $job = new DeliverScheduledControlSummary($receipt->id);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnNull();
    $job->handle($bot);
    $job->handle($bot);

    expect($receipt->fresh()->sent_at)->toBeNull()
        ->and($receipt->fresh()->status)->toBe('uncertain')
        ->and($receipt->fresh()->telegram_message_id)->toBeNull();
    expect($job->tries)->toBe(8)->and($job->backoff())->toBe([30, 60, 120, 300, 600, 600, 600]);
});

it('sends a scheduled summary outside a database transaction and records a confirmed receipt', function () {
    stubControlSummaryFormatter();
    controlDeliveryFixture();
    app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01');
    $receipt = TelegramScheduledControlSummaryDelivery::sole();
    $transactionLevelDuringSend = null;
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnUsing(function () use (&$transactionLevelDuringSend): int {
        $transactionLevelDuringSend = DB::transactionLevel();

        return 444;
    });

    (new DeliverScheduledControlSummary($receipt->id))->handle($bot);

    expect($transactionLevelDuringSend)->toBe(0)
        ->and($receipt->fresh()->telegram_message_id)->toBe(444)
        ->and($receipt->fresh()->status)->toBe('sent')
        ->and($receipt->fresh()->sent_at)->not->toBeNull();
});

it('does not recover uncertain or in-flight scheduled summary deliveries', function () {
    stubControlSummaryFormatter();
    controlDeliveryFixture();
    app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01');
    $receipt = TelegramScheduledControlSummaryDelivery::sole();
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);
    $receipt->update(['status' => 'uncertain', 'queued_at' => now()->subHours(2)]);

    expect(app(TelegramScheduledControlSummaryDeliveryService::class)->recoverPending())->toBe(0);
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);

    $receipt->update(['status' => 'sending']);
    expect(app(TelegramScheduledControlSummaryDeliveryService::class)->recoverPending())->toBe(0);
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);
});

it('marks an exhausted in-flight summary uncertain instead of making it eligible for resend', function () {
    stubControlSummaryFormatter();
    controlDeliveryFixture();
    app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01');
    $receipt = TelegramScheduledControlSummaryDelivery::sole();
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);
    $receipt->update(['status' => 'sending']);

    (new DeliverScheduledControlSummary($receipt->id))->failed(new RuntimeException('transport failed'));

    expect($receipt->fresh()->status)->toBe('uncertain')
        ->and(app(TelegramScheduledControlSummaryDeliveryService::class)->recoverPending())->toBe(0);
    Bus::assertDispatchedTimes(DeliverScheduledControlSummary::class, 1);
});

it('queues a control summary after commit when the database queue uses a separate connection', function () {
    stubControlSummaryFormatter();
    config(['queue.connections.database.connection' => 'mysql']);
    controlDeliveryFixture();

    expect(app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01')['queued'])->toBe(1)
        ->and(TelegramScheduledControlSummaryDelivery::sole()->status)->toBe('queued');
    Bus::assertDispatched(DeliverScheduledControlSummary::class, fn ($job): bool => $job->connection === 'database'
        && $job->queue === 'default'
        && $job->afterCommit === true
    );
});

it('uses Europe Rome calendar boundaries with UTC database timestamps', function () {
    config(['app.timezone' => 'UTC']);
    controlDeliveryFixture(['scheduled_for' => '2026-09-30 22:30:00', 'sent_at' => '2026-09-30 22:35:00']);
    controlDeliveryFixture(['scheduled_for' => '2026-10-01 22:30:00', 'sent_at' => '2026-10-01 22:35:00']);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals']['controls'])->toBe(1);
    expect($summary['deliveries'][0]['scheduled_for'])->toBe('2026-10-01T00:30:00+02:00');
});

it('waits for late active controls and actual late send windows before automatic summary', function () {
    $delivery = controlDeliveryFixture();
    $control = $delivery->scheduledMessage;
    $control->update(['send_time' => '18:00:00']);
    $sender = app(TelegramScheduledControlSummaryDeliveryService::class);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($sender->eligible('2026-10-01', $summary))->toBeFalse();
    $this->travelTo(Carbon::parse('2026-10-01 19:06:00', 'Europe/Rome'));
    expect($sender->eligible('2026-10-01', $summary))->toBeTrue();
    $delivery->update(['sent_at' => '2026-10-01 18:50:00']);
    expect($sender->eligible('2026-10-01', app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01')))->toBeFalse();
});

it('registers automatic summary with overlap protection without changing scheduled delivery worker', function () {
    $events = collect(app(Schedule::class)->events());
    $summary = $events->first(fn ($event) => str_contains($event->command ?? '', 'scheduled-controls-summary-send --only-if-due'));
    $poll = $events->first(fn ($event) => str_contains($event->command ?? '', 'telegram:scheduled-poll'));
    expect($summary->expression)->toBe('*/15 * * * *')->and($summary->withoutOverlapping)->toBeTrue();
    expect($poll->expression)->toBe('*/5 * * * *')->and($poll->withoutOverlapping)->toBeTrue();
    expect($events->first(fn ($event) => str_contains($event->command ?? '', 'telegram:scheduled-messages-send'))->expression)->toBe('* * * * *');
    expect($events->contains(fn ($event) => str_contains($event->command ?? '', 'telegram:analytics-poll')))->toBeFalse()
        ->and($events->contains(fn ($event) => str_contains($event->command ?? '', 'telegram:work-poll')))->toBeFalse();

    $originalAnalyticsPolling = config('services.telegram.analytics_polling_enabled');
    $originalWorkPolling = config('services.telegram.work_polling_enabled');
    $originalSchedule = app(Schedule::class);
    $enabledSchedule = new Schedule;
    Illuminate\Support\Facades\Schedule::swap($enabledSchedule);
    config([
        'services.telegram.analytics_polling_enabled' => true,
        'services.telegram.work_polling_enabled' => true,
    ]);

    try {
        require base_path('routes/console.php');
        $enabledEvents = collect($enabledSchedule->events());
        $analyticsPoll = $enabledEvents->first(fn ($event) => str_contains($event->command ?? '', 'telegram:analytics-poll'));
        $workPoll = $enabledEvents->first(fn ($event) => str_contains($event->command ?? '', 'telegram:work-poll'));

        expect($analyticsPoll)->not->toBeNull()
            ->and($analyticsPoll->expression)->toBe('*/2 * * * *')
            ->and($analyticsPoll->withoutOverlapping)->toBeTrue()
            ->and($workPoll)->not->toBeNull()
            ->and($workPoll->expression)->toBe('*/2 * * * *')
            ->and($workPoll->withoutOverlapping)->toBeTrue();
    } finally {
        Illuminate\Support\Facades\Schedule::swap($originalSchedule);
        config([
            'services.telegram.analytics_polling_enabled' => $originalAnalyticsPolling,
            'services.telegram.work_polling_enabled' => $originalWorkPolling,
        ]);
    }
});

it('does not trigger closing payments when second cleanings are only approaching completion', function (): void {
    expect(ScheduledControlTypes::triggersClosingPayments(ScheduledControlTypes::SECOND_CLEANINGS_FINISHING))->toBeFalse()
        ->and(ScheduledControlTypes::triggersClosingPayments(ScheduledControlTypes::CLOSING_CLEANINGS))->toBeTrue();
});
it('queues one district follow-up one hour after a negative answer and never duplicates it', function (): void {
    $delivery = controlDeliveryFixture(['control_type' => 'first_cleanings_finishing', 'bot_type' => 'work']);
    $topic = TelegramTopic::create(['telegram_chat_id' => $delivery->scheduledMessage->telegram_chat_record_id, 'telegram_thread_id' => '11', 'is_enabled' => true]);
    $delivery->scheduledMessage->update(['telegram_topic_record_id' => $topic->id]);
    $service = app(TelegramScheduledControlResponseService::class);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        $callback = scheduledControlCallback($delivery, 'followup-'.$district, $district === 'lodi' ? 'no' : 'yes');
        $callback['data'] = 'sc:'.$delivery->id.':'.$district.':'.($district === 'lodi' ? 'no' : 'yes');
        $service->captureCallback($callback);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 13:00:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});
it('keeps a follow-up answer in the original history and rejects stale original buttons', function (): void {
    $delivery = controlDeliveryFixture(['control_type' => 'first_cleanings_finishing', 'bot_type' => 'work']);
    $service = app(TelegramScheduledControlResponseService::class);
    $old = scheduledControlCallback($delivery, 'old-no', 'no');
    $old['data'] = 'sc:'.$delivery->id.':lodi:no';
    $service->captureCallback($old);

    $followup = TelegramScheduledControlFollowup::create([
        'delivery_id' => $delivery->id, 'district_key' => 'lodi', 'kind' => 'repeat',
        'sequence' => 1, 'due_at' => now()->subMinute(), 'sent_at' => now(),
        'status' => 'sent', 'telegram_message_id' => 75001,
    ]);

    $stale = scheduledControlCallback($delivery, 'stale-yes', 'yes');
    $stale['data'] = 'sc:'.$delivery->id.':lodi:yes';
    expect($service->captureCallback($stale)['status'])->toBe('unmatched');

    $reply = scheduledControlCallback($delivery, 'followup-yes', 'yes');
    $reply['message']['message_id'] = $followup->telegram_message_id;
    $reply['data'] = 'scf:'.$followup->id.':yes';
    $result = $service->captureCallback($reply);
    expect($result['status'])->toBe('saved')
        ->and($result['response']->delivery_id)->toBe($delivery->id)
        ->and($result['response']->followup_id)->toBe($followup->id)
        ->and($result['response']->district_key)->toBe('lodi')
        ->and($delivery->responses()->count())->toBe(2);
});
it('waits until the configured payment time after all five districts confirm cleanings', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 17:50:00', 'Europe/Rome'));
    $cleanings = controlDeliveryFixture([
        'control_type' => ScheduledControlTypes::CLOSING_CLEANINGS, 'bot_type' => 'work',
        'scheduled_for' => '2026-10-01 17:30:00', 'sent_at' => '2026-10-01 17:30:00',
    ]);
    $payment = closingPaymentTemplateFor($cleanings);
    $payment->update(['send_time' => '19:00:00']);
    $answers = app(TelegramScheduledControlResponseService::class);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        $callback = scheduledControlCallback($cleanings, 'payment-ready-'.$district, 'yes');
        $callback['data'] = 'sc:'.$cleanings->id.':'.$district.':yes';
        $answers->captureCallback($callback);
    }

    Bus::fake();
    $service = app(TelegramScheduledMessageDeliveryService::class);
    expect($service->queueClosingPayments($cleanings))->toBeTrue();
    $this->travelTo(Carbon::parse('2026-10-01 18:15:00', 'Europe/Rome'));
    expect($service->queueClosingPayments($cleanings))->toBeTrue();
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);

    $this->travelTo(Carbon::parse('2026-10-01 19:00:00', 'Europe/Rome'));
    $service->sendDue();
    $service->sendDue();
    expect(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(1);
    Bus::assertDispatched(DeliverScheduledTelegramMessage::class, 1);
});
it('reports a resolved district delay once in the original daily control', function (): void {
    $delivery = controlDeliveryFixture([
        'control_type' => 'first_cleanings_finishing', 'bot_type' => 'work',
        'scheduled_for' => '2026-10-01 12:00:00', 'sent_at' => '2026-10-01 12:00:00',
    ]);
    $service = app(TelegramScheduledControlResponseService::class);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $no = scheduledControlCallback($delivery, 'history-no', 'no');
    $no['data'] = 'sc:'.$delivery->id.':lodi:no';
    $service->captureCallback($no);
    $this->travelTo(Carbon::parse('2026-10-01 14:00:00', 'Europe/Rome'));
    $yes = scheduledControlCallback($delivery, 'history-yes', 'yes');
    $yes['data'] = 'sc:'.$delivery->id.':lodi:yes';
    $service->captureCallback($yes);

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $control = collect($summary['controls'])->firstWhere('control_type', 'first_cleanings_finishing');
    $lodi = collect($control['district_history'])->firstWhere('district', 'Lodi');
    expect($summary['totals']['controls'])->toBe(1)
        ->and($summary['totals']['delays_identified'])->toBe(1)
        ->and($summary['totals']['delays_resolved'])->toBe(1)
        ->and($lodi['status'])->toBe('confirmed')
        ->and($lodi['delay_minutes'])->toBe(120)
        ->and($lodi['resolved_at'])->not->toBeNull();
});
function followupControlFixture(string $type = 'first_cleanings_finishing', string $at = '2026-10-01 12:00:00'): TelegramScheduledMessageDelivery
{
    $delivery = controlDeliveryFixture([
        'control_type' => $type, 'bot_type' => 'work', 'scheduled_for' => $at, 'sent_at' => $at,
    ]);
    $topic = TelegramTopic::firstOrCreate([
        'telegram_chat_id' => $delivery->scheduledMessage->telegram_chat_record_id, 'telegram_thread_id' => '11',
    ], ['title' => 'Test topic', 'is_enabled' => true]);
    $delivery->scheduledMessage->update(['telegram_topic_record_id' => $topic->id]);

    return $delivery;
}

function answerFollowupDistrict(TelegramScheduledMessageDelivery $delivery, string $district, string $answer, string $id, int $user = 701): void
{
    $callback = scheduledControlCallback($delivery, $id, $answer, $user);
    $callback['data'] = 'sc:'.$delivery->id.':'.$district.':'.$answer;
    app(TelegramScheduledControlResponseService::class)->captureCallback($callback);
}

it('sends one reminder only for an unanswered district after twenty minutes', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    foreach (array_diff(array_keys(ScheduledControlTypes::DISTRICTS), ['lodi']) as $district) {
        answerFollowupDistrict($delivery, $district, 'yes', 'reminder-yes-'.$district);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:19:59', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
    $this->travelTo(Carbon::parse('2026-10-01 12:20:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    $followup = TelegramScheduledControlFollowup::query()->sole();
    expect($followup->district_key)->toBe('lodi')->and($followup->kind)->toBe('reminder');
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $lodi = collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi');
    expect($lodi['status'])->toBe('no_response')->and($summary['totals']['delays_identified'])->toBe(0);
});

it('cancels a queued follow-up if the district confirms before the worker sends it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'cancel-'.$district);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 13:00:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    $job = Bus::dispatched(DeliverScheduledControlFollowup::class)->sole();
    answerFollowupDistrict($delivery, 'lodi', 'yes', 'cancel-yes');
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldNotReceive('sendWorkControlMessage');
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    expect(TelegramScheduledControlFollowup::query()->sole()->status)->toBe('cancelled');
});

it('permits only two repeats and requires a fresh negative answer before the second', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'limit-'.$district);
    }
    $first = TelegramScheduledControlFollowup::create([
        'delivery_id' => $delivery->id, 'district_key' => 'lodi', 'kind' => 'repeat', 'sequence' => 1,
        'due_at' => '2026-10-01 13:00:00', 'sent_at' => '2026-10-01 13:00:00',
        'telegram_message_id' => 75010, 'status' => 'sent',
    ]);
    $this->travelTo(Carbon::parse('2026-10-01 14:00:00', 'Europe/Rome'));
    Bus::fake();
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);

    $callback = scheduledControlCallback($delivery, 'repeat-no-1', 'no');
    $callback['message']['message_id'] = $first->telegram_message_id;
    $callback['data'] = 'scf:'.$first->id.':no';
    app(TelegramScheduledControlResponseService::class)->captureCallback($callback);
    $this->travelTo(Carbon::parse('2026-10-01 15:00:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    $second = TelegramScheduledControlFollowup::query()->where('sequence', 2)->sole();
    $second->update(['status' => 'sent', 'sent_at' => now(), 'telegram_message_id' => 75011]);
    $callback['id'] = 'repeat-no-2';
    $callback['message']['message_id'] = $second->telegram_message_id;
    $callback['data'] = 'scf:'.$second->id.':no';
    app(TelegramScheduledControlResponseService::class)->captureCallback($callback);
    $this->travelTo(Carbon::parse('2026-10-01 16:00:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    expect(TelegramScheduledControlFollowup::query()->where('kind', 'repeat')->count())->toBe(2);
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});

it('does not repeat a previous cleaning stage after the configured next stage has begun', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 11:15:00', 'Europe/Rome'));
    $delivery = followupControlFixture('first_cleanings_started', '2026-10-01 10:25:00');
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'stage-'.$district);
    }
    TelegramScheduledMessage::create([
        'name' => 'First finishing', 'control_type' => 'first_cleanings_finishing',
        'telegram_chat_record_id' => $delivery->scheduledMessage->telegram_chat_record_id,
        'telegram_topic_record_id' => $delivery->scheduledMessage->telegram_topic_record_id,
        'message' => 'First cleanings finishing?', 'send_time' => '12:00:00', 'weekdays' => [4], 'enabled' => true,
    ]);
    $this->travelTo(Carbon::parse('2026-10-01 12:15:00', 'Europe/Rome'));
    Bus::fake();
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
});

it('uses elapsed time rather than the repeated wall clock hour for follow-ups on the autumn DST transition', function (): void {
    $this->travelTo(Carbon::parse('2026-10-25T02:30:00+02:00')->setTimezone('Europe/Rome'));
    $delivery = followupControlFixture('first_cleanings_finishing', '2026-10-25 02:00:00');
    $delivery->update(['sent_at_utc' => '2026-10-25 00:00:00']);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'dst-'.$district);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-25T02:29:00+01:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
    $this->travelTo(Carbon::parse('2026-10-25T02:30:00+01:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();

    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});
it('routes a Work bot follow-up callback into the original control history', function (): void {
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true], 200)]);
    $delivery = followupControlFixture();
    answerFollowupDistrict($delivery, 'lodi', 'no', 'work-followup-no');
    $followup = TelegramScheduledControlFollowup::create([
        'delivery_id' => $delivery->id, 'district_key' => 'lodi', 'kind' => 'repeat', 'sequence' => 1,
        'due_at' => now(), 'sent_at' => now(), 'status' => 'sent', 'telegram_message_id' => 76001,
    ]);
    $callback = scheduledControlCallback($delivery, 'work-followup-yes', 'yes');
    $callback['message']['message_id'] = $followup->telegram_message_id;
    $callback['data'] = 'scf:'.$followup->id.':yes';

    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
    $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
    expect($delivery->responses()->count())->toBe(2)
        ->and($delivery->responses()->latest('id')->first()->followup_id)->toBe($followup->id);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'answerCallbackQuery'));
});

it('associates a text reply to a follow-up with its district and original control', function (): void {
    $delivery = followupControlFixture();
    $followup = TelegramScheduledControlFollowup::create([
        'delivery_id' => $delivery->id, 'district_key' => 'como', 'kind' => 'reminder', 'sequence' => 1,
        'due_at' => now(), 'sent_at' => now(), 'status' => 'sent', 'telegram_message_id' => 76002,
    ]);
    $payload = controlReplyPayload($delivery, 'Да', 76003);
    $payload['message']['reply_to_message']['message_id'] = $followup->telegram_message_id;
    $response = captureControlReply($payload);
    expect($response->delivery_id)->toBe($delivery->id)
        ->and($response->followup_id)->toBe($followup->id)
        ->and($response->district_key)->toBe('como')
        ->and($response->classification)->toBe('confirmed');
});

it('keeps conflicting district confirmations from triggering payment', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 18:30:00', 'Europe/Rome'));
    $delivery = followupControlFixture(ScheduledControlTypes::CLOSING_CLEANINGS, '2026-10-01 17:30:00');
    closingPaymentTemplateFor($delivery);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, 'yes', 'conflict-yes-'.$district);
    }
    answerFollowupDistrict($delivery, 'lodi', 'no', 'conflict-no', 702);
    Bus::fake();
    $service = app(TelegramScheduledMessageDeliveryService::class);
    $service->queueClosingPayments($delivery);
    expect(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect(collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi')['status'])->toBe('conflict');
    answerFollowupDistrict($delivery, 'lodi', 'yes', 'conflict-resolved', 702);
    $service->queueClosingPayments($delivery);
    expect(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(1);
    Bus::assertDispatched(DeliverScheduledTelegramMessage::class, 1);
});

it('accepts confirmed districts from a multi-line text response before scheduling payments', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 18:30:00', 'Europe/Rome'));
    $delivery = followupControlFixture(ScheduledControlTypes::CLOSING_CLEANINGS, '2026-10-01 17:30:00');
    closingPaymentTemplateFor($delivery);
    $text = "Lambrate — да\nLodi — да\nCertosa — да\nNavigli — да\nComo — да";
    $payload = controlReplyPayload($delivery, $text, 76004, 701, '2026-10-01 18:30:00');
    $response = captureControlReply($payload);
    expect($response)->not->toBeNull()
        ->and(app(TelegramScheduledControlResponseService::class)->allDistrictsConfirmed($delivery))->toBeTrue();
    Bus::fake();
    app(TelegramScheduledMessageDeliveryService::class)->queueClosingPayments($delivery);
    Bus::assertDispatched(DeliverScheduledTelegramMessage::class, 1);
});

it('rechecks payment confirmation in the worker and does not repeat an uncertain send', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 18:30:00', 'Europe/Rome'));
    $delivery = followupControlFixture(ScheduledControlTypes::CLOSING_CLEANINGS, '2026-10-01 17:30:00');
    closingPaymentTemplateFor($delivery);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, 'yes', 'worker-yes-'.$district);
    }
    Bus::fake();
    $service = app(TelegramScheduledMessageDeliveryService::class);
    $service->queueClosingPayments($delivery);
    $job = Bus::dispatched(DeliverScheduledTelegramMessage::class)->sole();
    answerFollowupDistrict($delivery, 'lodi', 'no', 'worker-revoked');
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldNotReceive('sendWorkControlMessage');
    $job->handle($telegram);
    $payment = TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->sole();
    expect($payment->status)->toBe('cancelled');

    answerFollowupDistrict($delivery, 'lodi', 'yes', 'worker-restored');
    $service->queueClosingPayments($delivery);
    $retry = Bus::dispatched(DeliverScheduledTelegramMessage::class)->last();
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldReceive('sendWorkControlMessage')->once()->andThrow(new ConnectionException('test'));
    $retry->handle($telegram);
    $retry->handle($telegram);
    $service->queueClosingPayments($delivery);
    expect($payment->fresh()->status)->toBe('uncertain');
    Bus::assertDispatched(DeliverScheduledTelegramMessage::class, 2);
});

it('uses elapsed time across the spring DST jump and never follows a control into the next day', function (): void {
    $this->travelTo(Carbon::parse('2026-03-29T01:30:00+01:00')->setTimezone('Europe/Rome'));
    $delivery = followupControlFixture('first_cleanings_finishing', '2026-03-29 01:30:00');
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'spring-'.$district);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-03-29T03:29:00+02:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
    $this->travelTo(Carbon::parse('2026-03-29T03:30:00+02:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
    $this->travelTo(Carbon::parse('2026-03-30T03:30:00+02:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});
it('recovers a stranded queued follow-up without sending it twice', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'recover-'.$district);
    }
    $followup = TelegramScheduledControlFollowup::create([
        'delivery_id' => $delivery->id, 'district_key' => 'lodi', 'kind' => 'repeat', 'sequence' => 1,
        'due_at' => '2026-10-01 13:00:00', 'status' => 'queued',
        'dispatch_requested_at_utc' => '2026-10-01 10:50:00',
    ]);
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 13:00:00', 'Europe/Rome'));
    $service = app(TelegramScheduledMessageDeliveryService::class);
    $service->sendDue();
    $service->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
    $job = Bus::dispatched(DeliverScheduledControlFollowup::class)->sole();
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldReceive('sendWorkControlMessage')->once()->andReturn(77001);
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    expect($followup->fresh()->status)->toBe('sent')
        ->and($followup->fresh()->telegram_message_id)->toBe(77001);
});
it('uses the response version available at the summary cutoff when a reply was edited later', function (): void {
    $delivery = followupControlFixture();
    $payload = controlReplyPayload($delivery, 'Lodi — нет', 76500, 701, '2026-10-01 12:10:00');
    captureControlReply($payload);
    $edited = $payload['message'];
    $edited['text'] = 'Lodi — да';
    $edited['edit_date'] = Carbon::parse('2026-10-01 23:00:00', 'Europe/Rome')->timestamp;
    $update = ['update_id' => 76501, 'edited_message' => $edited];
    $stored = app(TelegramUpdateIngestService::class)->ingest($update);
    app(TelegramScheduledControlResponseService::class)->capture($stored, $edited);
    $this->travelTo(Carbon::parse('2026-10-01 23:10:00', 'Europe/Rome'));
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $lodi = collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi');
    expect($lodi['status'])->toBe('problem')->and($lodi['resolved_at'])->toBeNull();
});
it('classifies an explicit partial response without treating it as completion', function (): void {
    expect(app(ScheduledControlResponseClassifier::class)->classify('Частично')['classification'])->toBe('partial');
});

it('keeps an uncertain follow-up send from being repeated', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'uncertain-'.$district);
    }
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 13:00:00', 'Europe/Rome'));
    $service = app(TelegramScheduledMessageDeliveryService::class);
    $service->sendDue();
    $job = Bus::dispatched(DeliverScheduledControlFollowup::class)->sole();
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldReceive('sendWorkControlMessage')->once()->andThrow(new ConnectionException('test'));
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    $this->travelTo(Carbon::parse('2026-10-01 13:10:00', 'Europe/Rome'));
    $service->sendDue();
    expect(TelegramScheduledControlFollowup::query()->sole()->status)->toBe('uncertain');
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});
it('orders district answers by the actual instant when the autumn clock repeats', function (): void {
    $this->travelTo(Carbon::parse('2026-10-25T02:30:00+02:00')->setTimezone('Europe/Rome'));
    $delivery = followupControlFixture('first_cleanings_finishing', '2026-10-25 01:30:00');
    answerFollowupDistrict($delivery, 'lodi', 'no', 'dst-order-no');
    $this->travelTo(Carbon::parse('2026-10-25T02:15:00+01:00')->setTimezone('Europe/Rome'));
    answerFollowupDistrict($delivery, 'lodi', 'yes', 'dst-order-yes');
    $state = app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery)['lodi']['telegram:701'];
    expect($state['classification'])->toBe('confirmed');
});
it('does not guess the repeat time for an old ambiguous local timestamp without UTC', function (): void {
    $this->travelTo(Carbon::parse('2026-10-25T02:30:00+02:00')->setTimezone('Europe/Rome'));
    $delivery = followupControlFixture('first_cleanings_finishing', '2026-10-25 01:30:00');
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, $district === 'lodi' ? 'no' : 'yes', 'old-dst-'.$district);
    }
    $delivery->responses()->where('district_key', 'lodi')->update(['responded_at_utc' => null]);
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-25T03:30:00+01:00')->setTimezone('Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-25');
    expect(collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi')['time_uncertain'])->toBeTrue();
});
it('honors disabled follow-ups, a custom interval, and the daily cutoff', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00', 'Europe/Rome'));
    $delivery = followupControlFixture();
    $delivery->scheduledMessage->update([
        'followup_interval_minutes' => 30, 'followup_reminder_enabled' => false, 'followup_cutoff' => '12:45:00',
    ]);
    answerFollowupDistrict($delivery, 'lodi', 'no', 'settings-no');
    Bus::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:29:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledControlFollowup::class);
    $this->travelTo(Carbon::parse('2026-10-01 12:30:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
    $delivery->scheduledMessage->update(['enabled' => false]);
    $job = Bus::dispatched(DeliverScheduledControlFollowup::class)->sole();
    $telegram = Mockery::mock(TelegramBotService::class);
    $telegram->shouldNotReceive('sendWorkControlMessage');
    $job->handle($telegram, app(TelegramScheduledControlFollowupService::class));
    expect(TelegramScheduledControlFollowup::query()->sole()->status)->toBe('cancelled');

    $this->travelTo(Carbon::parse('2026-10-01 13:00:00', 'Europe/Rome'));
    $delivery->scheduledMessage->update(['enabled' => true]);
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledControlFollowup::class, 1);
});

it('does not send payments before 18 even if the configured time is earlier', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 17:45:00', 'Europe/Rome'));
    $delivery = followupControlFixture(ScheduledControlTypes::CLOSING_CLEANINGS, '2026-10-01 17:30:00');
    closingPaymentTemplateFor($delivery)->update(['send_time' => '16:00:00']);
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        answerFollowupDistrict($delivery, $district, 'yes', 'floor-'.$district);
    }
    Bus::fake();
    app(TelegramScheduledMessageDeliveryService::class)->queueClosingPayments($delivery);
    $this->travelTo(Carbon::parse('2026-10-01 17:59:59', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
    $this->travelTo(Carbon::parse('2026-10-01 18:00:00', 'Europe/Rome'));
    app(TelegramScheduledMessageDeliveryService::class)->sendDue();
    Bus::assertDispatched(DeliverScheduledTelegramMessage::class, 1);
});

it('does not queue payments after confirming all districts on the intermediate second-cleaning control', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 18:30:00', 'Europe/Rome'));
    config(['services.telegram.bot_token' => 'work-test-token', 'services.telegram.work_backlog_callback_guard_enabled' => false]);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true], 200)]);
    $delivery = followupControlFixture(ScheduledControlTypes::SECOND_CLEANINGS_FINISHING, '2026-10-01 14:00:00');
    closingPaymentTemplateFor($delivery);
    Bus::fake();
    foreach (array_keys(ScheduledControlTypes::DISTRICTS) as $district) {
        $callback = scheduledControlCallback($delivery, 'second-only-'.$district, 'yes');
        $callback['data'] = 'sc:'.$delivery->id.':'.$district.':yes';
        $this->postJson('/telegram/work-webhook/work-test', ['callback_query' => $callback])->assertOk();
    }
    expect(TelegramScheduledMessageDelivery::query()->where('control_type', ScheduledControlTypes::CLOSING_PAYMENTS)->count())->toBe(0);
    Bus::assertNotDispatched(DeliverScheduledTelegramMessage::class);
});

it('keeps an earlier negative answer unresolved when an edited reply becomes a question', function (): void {
    $delivery = followupControlFixture();
    $payload = controlReplyPayload($delivery, 'Lodi — нет', 76510, 701, '2026-10-01 12:10:00');
    captureControlReply($payload);
    $edited = $payload['message'];
    $edited['text'] = 'Lodi — уточняю?';
    $edited['edit_date'] = Carbon::parse('2026-10-01 12:30:00', 'Europe/Rome')->timestamp;
    $update = ['update_id' => 76511, 'edited_message' => $edited];
    $stored = app(TelegramUpdateIngestService::class)->ingest($update);
    app(TelegramScheduledControlResponseService::class)->capture($stored, $edited);
    $states = app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery);
    expect($states['lodi']['telegram:701']['classification'])->toBe('problem');
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect(collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi')['status'])->toBe('problem');
});

it('does not treat an edited question as a confirmed district answer', function (): void {
    $delivery = followupControlFixture();
    $payload = controlReplyPayload($delivery, 'Lodi — да', 76520, 702, '2026-10-01 12:10:00');
    captureControlReply($payload);
    $edited = $payload['message'];
    $edited['text'] = 'Lodi — уточняю?';
    $edited['edit_date'] = Carbon::parse('2026-10-01 12:30:00', 'Europe/Rome')->timestamp;
    $stored = app(TelegramUpdateIngestService::class)->ingest(['update_id' => 76521, 'edited_message' => $edited]);
    app(TelegramScheduledControlResponseService::class)->capture($stored, $edited);
    $states = app(TelegramScheduledControlResponseService::class)->districtAnswerStates($delivery);
    expect($states['lodi']['telegram:702']['classification'])->toBe('unclear');
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect(collect($summary['controls'][0]['district_history'])->firstWhere('district', 'Lodi')['status'])->toBe('no_response');
});

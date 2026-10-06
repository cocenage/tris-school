<?php

use App\Jobs\DeliverScheduledControlSummary;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
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
use App\Services\Telegram\TelegramScheduledControlResponseService;
use App\Services\Telegram\TelegramScheduledControlStatistics;
use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDeliveryService;
use App\Services\Telegram\TelegramScheduledControlSummaryFormatter;
use App\Services\Telegram\TelegramScheduledInboundProcessor;
use App\Services\Telegram\TelegramUpdateIngestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
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
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'queue.connections.database.connection' => null]);
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    foreach (['2026_09_27_000000_create_telegram_scheduled_messages_tables.php', '2026_10_01_020000_create_scheduled_control_response_tables.php', '2026_10_03_000000_allow_replyless_scheduled_control_responses.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
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
    ['Возможно всё хорошо', 'unclear'],
]);

it('preserves later replies but uses each responders first reply for median latency', function () {
    $delivery = controlDeliveryFixture();
    captureControlReply(controlReplyPayload($delivery, 'Да', 100, 101, '2026-10-01 09:36:00'));
    captureControlReply(controlReplyPayload($delivery, 'Курьер не приехал', 101, 101, '2026-10-01 09:50:00'));
    captureControlReply(controlReplyPayload($delivery, 'Проверено', 102, 102, '2026-10-01 09:38:00'));
    $stats = app(TelegramScheduledControlStatistics::class)->delivery($delivery->fresh('responses'));
    expect($stats['response_count'])->toBe(3)->and($stats['unique_responder_count'])->toBe(2)
        ->and($stats['median_response_latency_seconds'])->toBe(120.0)->and($stats['result'])->toBe('problem');
});

it('exposes the six canonical scheduled control types', function () {
    expect(ScheduledControlTypes::options())->toEqual([
        'schedule_checked' => 'Время уборок и заметки проверены',
        'first_cleanings_started' => 'Все первые уборки начались',
        'first_cleanings_finishing' => 'Первые уборки подходят к завершению',
        'second_cleanings_finishing' => 'Вторые уборки подходят к завершению',
        'couriers_completed' => 'Курьеры завершили все доставки',
        'extra_payments_completed' => 'Все доплаты произведены',
    ]);
});

it('renders only the six canonical control types and describes zero captured data clearly', function () {
    foreach (array_keys(ScheduledControlTypes::LABELS) as $controlType) {
        controlDeliveryFixture(['control_type' => $controlType]);
    }
    controlDeliveryFixture(['control_type' => 'control_question']);

    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);

    expect($summary['controls'])->toHaveCount(6)
        ->and(collect($summary['controls'])->pluck('control_type')->all())->toEqual(array_keys(ScheduledControlTypes::LABELS))
        ->and($text)->toContain('Время уборок и заметки проверены: нет данных', 'Все доплаты произведены: нет данных')
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
        ->and($text)->toContain('📊 TRIS — контроль дня · 01.10.2026', 'Отклонения', '20 мин', 'не рассчитывается')
        ->not->toContain("\nНет ответа\n", 'Маша опоздает', '1/5');
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

it('builds daily totals and a human summary without a fabricated denominator', function () {
    captureControlReply(controlReplyPayload(controlDeliveryFixture(), 'Курьер не приехал'));
    controlDeliveryFixture();
    $summary = app(TelegramScheduledControlSummaryBuilder::class)->build('2026-10-01');
    expect($summary['totals'])->toMatchArray(['controls' => 2, 'responded' => 1, 'problem' => 1, 'no_response' => 1]);
    $text = app(TelegramScheduledControlSummaryFormatter::class)->format($summary);
    expect($text)->toContain('TRIS — контроль дня', '01.10.2026', 'Курьер не приехал', 'не рассчитывается')
        ->not->toContain('Нет ответа', 'Ответили: 1 сотрудника', '1/5');
    expect(mb_strlen(html_entity_decode($text)))->toBeLessThan(4096);
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
        ->and($requests[0]['allowed_updates'])->toBe(['message', 'edited_message'])
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

it('skips irrelevant pending update types and continues to scheduled messages', function () {
    config(['services.telegram.scheduled_bot_token' => 'scheduled-poll-token']);
    $payload = controlReplyPayload(controlDeliveryFixture(['control_type' => 'schedule_checked']), 'Да, проверено', 255);
    $payload['update_id'] = 505;
    $irrelevant = ['update_id' => 504, 'callback_query' => ['id' => 'old-pending-update']];

    fakeScheduledPollResponses()
        ->push(['ok' => true, 'result' => [$payload, $irrelevant]])
        ->push(['ok' => true, 'result' => []]);

    expect(Artisan::call('telegram:scheduled-poll'))->toBe(0)
        ->and(TelegramMessage::query()->where('message_id', '255')->count())->toBe(1)
        ->and(TelegramScheduledMessageResponse::count())->toBe(1);

    $requests = Http::recorded()->pluck(0)->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['allowed_updates'])->toBe(['message', 'edited_message'])
        ->and($requests[1]['allowed_updates'])->toBe(['message', 'edited_message'])
        ->and($requests[1]['offset'])->toBe(506);
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

it('does not mark failed summary delivery sent and allows queue retry', function () {
    controlDeliveryFixture();
    app(TelegramScheduledControlSummaryDeliveryService::class)->queue('2026-10-01');
    $receipt = TelegramScheduledControlSummaryDelivery::sole();
    $job = new DeliverScheduledControlSummary($receipt->id);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnNull();
    expect(fn () => $job->handle($bot))->toThrow(RuntimeException::class);
    expect($receipt->fresh()->sent_at)->toBeNull()->and($receipt->fresh()->status)->toBe('retrying');
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturn(444);
    $job->handle($bot);
    $job->handle($bot);
    expect($receipt->fresh()->telegram_message_id)->toBe(444)->and($receipt->fresh()->status)->toBe('sent');
    expect($job->tries)->toBe(8)->and($job->backoff())->toBe([30, 60, 120, 300, 600, 600, 600]);
});

it('queues a control summary after commit when the database queue uses a separate connection', function () {
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
    expect($poll->expression)->toBe('* * * * *')->and($poll->withoutOverlapping)->toBeTrue();
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
            ->and($analyticsPoll->expression)->toBe('* * * * *')
            ->and($analyticsPoll->withoutOverlapping)->toBeTrue()
            ->and($workPoll)->not->toBeNull()
            ->and($workPoll->expression)->toBe('* * * * *')
            ->and($workPoll->withoutOverlapping)->toBeTrue();
    } finally {
        Illuminate\Support\Facades\Schedule::swap($originalSchedule);
        config([
            'services.telegram.analytics_polling_enabled' => $originalAnalyticsPolling,
            'services.telegram.work_polling_enabled' => $originalWorkPolling,
        ]);
    }
});

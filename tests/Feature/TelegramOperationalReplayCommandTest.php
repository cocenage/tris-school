<?php

use App\Models\TelegramMessage;
use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    Http::fake();
});
afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
});

it('catches up the frozen current day through the captured clock without duplicating live work', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Rome'));
    config(['services.telegram.operational_chat_ids' => ['-1001']]);

    $live = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-07-23 08:00:00', '901');
    $missed = TelegramOperationalTestDatabase::message('В ванной грязно, качество уборки плохое', '2026-07-23 09:00:00', '902', threadId: '12');
    $future = TelegramOperationalTestDatabase::message('Не работает дверь в квартире', '2026-07-23 13:00:00', '903', threadId: '13');
    $otherChat = TelegramOperationalTestDatabase::message('Не работает свет в квартире', '2026-07-23 10:00:00', '904', chatId: '-1002');
    app(TelegramOperationalEventObserver::class)->observe($live);

    expect(Artisan::call('telegram:operational-replay', ['--through-now' => true, '--json' => true]))->toBe(0);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($first)->toMatchArray([
        'from' => '2026-07-23', 'to' => '2026-07-23',
        'examined' => 2, 'idempotent_reused' => 1, 'failed' => 0, 'telegram_actions' => 0,
    ])->and($first['cutoff_at'])->toContain('2026-07-23T12:00:00')
        ->and(TelegramOperationalObservation::query()->where('evaluation_kind', 'message')->count())->toBe(2)
        ->and(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and($missed->operationalObservations()->exists())->toBeTrue()
        ->and($future->operationalObservations()->exists())->toBeFalse()
        ->and($otherChat->operationalObservations()->exists())->toBeFalse()
        ->and(Http::recorded())->toHaveCount(0);

    expect(Artisan::call('telegram:operational-replay', ['--date' => '2026-07-23', '--through-now' => true, '--json' => true]))->toBe(0);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($second['examined'])->toBe(2)
        ->and($second['idempotent_reused'])->toBe(2)
        ->and(TelegramOperationalObservation::query()->where('evaluation_kind', 'message')->count())->toBe(2)
        ->and(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and(Http::recorded())->toHaveCount(0);
});

it('keeps the current-day guard unless through-now is explicit and rejects other through-now selectors', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Europe/Rome'));
    TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-07-23 08:00:00', '911');

    foreach ([
        ['--date' => '2026-07-23'],
        ['--date' => '2026-07-22', '--through-now' => true],
        ['--from' => '2026-07-23', '--to' => '2026-07-23', '--through-now' => true],
    ] as $arguments) {
        expect(Artisan::call('telegram:operational-replay', $arguments))->toBe(1);
    }

    expect(TelegramOperationalObservation::query()->count())->toBe(0)
        ->and(TelegramOperationalEvent::query()->count())->toBe(0);
});

it('replays one historical day chronologically and remains idempotent and silent', function () {
    TelegramOperationalTestDatabase::message('Проблема с замком, он не работает', '2026-06-17 08:00:00', '2');
    TelegramOperationalTestDatabase::message('Всем доброе утро', '2026-06-17 07:00:00', '1');

    expect(Artisan::call('telegram:operational-replay', [
        '--date' => '2026-06-17',
        '--json' => true,
    ]))->toBe(0);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['examined'])->toBe(2)
        ->and($result['telegram_actions'])->toBe(0);

    $count = TelegramOperationalEvent::query()->count();

    $this->artisan('telegram:operational-replay', ['--date' => '2026-06-17', '--json' => true])
        ->assertExitCode(0);

    expect($count)->toBe(1)
        ->and(TelegramOperationalEvent::query()->count())->toBe(1)
        ->and(Http::recorded())->toHaveCount(0);
});

it('processes responses at a deadline before unanswered maturation', function () {
    TelegramOperationalTestDatabase::message('Кто привезёт ключи в квартиру?', '2026-06-17 08:00:00', '10');
    TelegramOperationalTestDatabase::message(
        'Я привезу ключи, уже взял в работу',
        '2026-06-17 12:00:00',
        '11',
        ['message' => ['reply_to_message' => ['message_id' => 10]]],
    );

    $this->artisan('telegram:operational-replay', ['--date' => '2026-06-17', '--json' => true])
        ->assertExitCode(0);

    expect(TelegramOperationalEvent::query()->whereJsonContains('types', 'unanswered_question')->count())->toBe(0);
});

it('meets selective-observation thresholds on the reviewed one-day fixture', function () {
    $ordinary = [
        'Доброе утро', 'Всем привет', 'Сообщение прочитано', 'Хорошего дня',
        'Принято', 'Ок', 'Увидимся завтра', 'Обед в холодильнике',
        'Сегодня солнечно', 'Фото с прогулки', 'Поздравляю', 'До встречи',
        'Я на связи', 'Согласна', 'Понятно', 'Отлично',
    ];
    $meaningful = [
        'Не работает замок в квартире',
        'Есть риск, что гость не попадёт в квартиру',
        'Опоздаю на уборку на 30 минут',
        'Квартира плохо убрана, осталась грязь',
    ];

    foreach ([...$ordinary, ...$meaningful] as $index => $text) {
        TelegramOperationalTestDatabase::message(
            $text,
            '2026-06-17 '.str_pad((string) (8 + intdiv($index, 6)), 2, '0', STR_PAD_LEFT).':'.str_pad((string) (($index % 6) * 10), 2, '0', STR_PAD_LEFT).':00',
            (string) (700 + $index),
        );
    }

    expect(Artisan::call('telegram:operational-replay', [
        '--date' => '2026-06-17',
        '--json' => true,
    ]))->toBe(0);

    $completed = TelegramOperationalObservation::query()
        ->where('evaluation_kind', 'message')
        ->where('state', 'completed');
    $noEventCount = (clone $completed)->where('outcome', 'no_event')->count();
    $eventCount = TelegramOperationalEvent::query()->count();

    expect($completed->count())->toBe(20)
        ->and($noEventCount)->toBe(16)
        ->and($eventCount)->toBe(4)
        ->and($noEventCount / 20)->toBeGreaterThanOrEqual(0.80)
        ->and($noEventCount / count($ordinary))->toBe(1)
        ->and(Http::recorded())->toHaveCount(0);
});

it('rejects invalid replay selectors before ledger writes', function (array $arguments) {
    TelegramOperationalTestDatabase::message('Не работает замок', '2026-06-17 08:00:00', '801');

    expect(Artisan::call('telegram:operational-replay', $arguments))->toBe(1)
        ->and(TelegramOperationalObservation::query()->count())->toBe(0)
        ->and(TelegramOperationalEvent::query()->count())->toBe(0);
})->with([
    'missing selector' => [[]],
    'unpaired range' => [['--from' => '2026-06-17']],
    'mixed selectors' => [['--date' => '2026-06-17', '--from' => '2026-06-17', '--to' => '2026-06-17']],
    'more than seven dates' => [['--from' => '2026-06-01', '--to' => '2026-06-08']],
    'reverse range' => [['--from' => '2026-06-18', '--to' => '2026-06-17']],
    'future boundary' => [['--date' => '2099-01-01']],
]);

it('replays an inclusive seven-day range and leaves post-range deadlines pending', function () {
    TelegramOperationalTestDatabase::message('Не работает замок', '2026-06-11 00:00:00', '811');
    TelegramOperationalTestDatabase::message('Кто привезёт ключи в квартиру?', '2026-06-17 22:00:00', '812');

    expect(Artisan::call('telegram:operational-replay', [
        '--from' => '2026-06-11',
        '--to' => '2026-06-17',
        '--json' => true,
    ]))->toBe(0);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toMatchArray([
        'from' => '2026-06-11',
        'to' => '2026-06-17',
        'examined' => 2,
        'telegram_actions' => 0,
    ])->and(TelegramOperationalEvent::query()->whereJsonContains('types', 'unanswered_question')->count())->toBe(0);
});

it('reports a per-message failure and continues the replay', function () {
    TelegramOperationalTestDatabase::message('Не работает замок', '2026-06-17 08:00:00', '821');
    TelegramOperationalTestDatabase::message('Опоздаю на уборку', '2026-06-17 09:00:00', '822');

    $observer = Mockery::mock(TelegramOperationalEventObserver::class);
    $observer->shouldReceive('observe')->twice()->andReturnUsing(function ($message) {
        if ($message->message_id === '821') {
            throw new RuntimeException('fixture failure');
        }

        return [
            'outcome' => 'no_event', 'event_key' => null, 'idempotent_reuse' => false,
            'unanswered_due_at' => null,
        ];
    });
    $this->app->instance(TelegramOperationalEventObserver::class, $observer);

    expect(Artisan::call('telegram:operational-replay', ['--date' => '2026-06-17', '--json' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['examined'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($result['no_event'])->toBe(1)
        ->and($result['failure_message_ids'])->toHaveCount(1);
});

it('produces the same lifecycle through direct observation and replay', function () {
    $seed = function (): void {
        TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '831');
        TelegramOperationalTestDatabase::message(
            'Уже проверяю дверь', '2026-06-17 08:10:00', '832',
            ['message' => ['reply_to_message' => ['message_id' => 831]]],
        );
        TelegramOperationalTestDatabase::message(
            'Починили, всё готово', '2026-06-17 08:30:00', '833',
            ['message' => ['reply_to_message' => ['message_id' => 831]]],
        );
    };
    $snapshot = fn () => TelegramOperationalEvent::query()->with('evidence.observation.message')
        ->orderBy('event_key')->get()->map(fn ($event) => [
            'key' => $event->event_key,
            'status' => $event->status,
            'types' => $event->types,
            'transitions' => $event->evidence->pluck('transition')->all(),
            'sources' => $event->evidence->map(fn ($evidence) => $evidence->observation->message->message_id)->all(),
        ])->all();

    $seed();
    $observer = app(TelegramOperationalEventObserver::class);
    foreach (TelegramMessage::query()->orderBy('sent_at')->orderBy('id')->get() as $message) {
        $observer->observe($message);
    }
    $live = $snapshot();

    TelegramOperationalTestDatabase::purge();
    TelegramOperationalTestDatabase::refresh();
    $seed();
    expect(Artisan::call('telegram:operational-replay', ['--date' => '2026-06-17', '--json' => true]))->toBe(0);

    expect($snapshot())->toBe($live);
});

<?php

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\Support\TelegramOperationalTestDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => TelegramOperationalTestDatabase::refresh());
afterEach(fn () => TelegramOperationalTestDatabase::purge());

function eveningItems(array $preview): Collection
{
    return collect($preview['sections'])->flatMap(fn (array $section) => $section['items']);
}

it('projects lifecycle as of the selected day without leaking a later resolution', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $problem = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        sentAt: '2026-06-17 08:00:00',
        messageId: '101',
    );
    $created = $observer->observe($problem);

    $resolution = TelegramOperationalTestDatabase::message(
        'Замок исправили, всё работает',
        sentAt: '2026-06-18 09:00:00',
        messageId: '102',
        raw: ['message' => ['reply_to_message' => ['message_id' => 101]]],
        userId: '102',
    );
    $observer->observe($resolution);

    $dayOne = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $dayTwo = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-18');
    $dayOneItem = eveningItems($dayOne)->firstWhere('event_key', $created['event_key']);
    $dayTwoItem = eveningItems($dayTwo)->firstWhere('event_key', $created['event_key']);

    expect($dayOneItem)
        ->not->toBeNull()
        ->and($dayOneItem['status'])->toBe('open')
        ->and($dayOneItem['evidence'])->toHaveCount(1)
        ->and($dayOneItem['evidence'][0]['telegram_message_id'])->toBe('101')
        ->and($dayTwoItem)
        ->not->toBeNull()
        ->and($dayTwoItem['status'])->toBe('resolved')
        ->and($dayTwoItem['evidence'])->toHaveCount(2);
});

it('uses the weakest evidence confidence and preserves uncertainty', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Похоже, есть проблема с ключами',
        messageId: '201',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);

    TelegramOperationalEventEvidence::query()->update([
        'confidence' => 'low',
        'uncertainty' => 'Требуется подтверждение.',
    ]);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $item = collect($preview['events'])->first();

    expect($item['confidence'])->toBe('low')
        ->and($item['uncertainty'])->toBe('Требуется подтверждение.')
        ->and($item['status'])->toBe('open')
        ->and($preview['sections'])->toBe([]);
});

it('keeps an uncertain open question visible without hiding its low confidence in json', function () {
    $message = TelegramOperationalTestDatabase::message('Во сколько заезд?');
    app(TelegramOperationalEventObserver::class)->observe($message);
    TelegramOperationalEventEvidence::query()->update([
        'confidence' => 'low',
        'uncertainty' => 'Требуется уточнение.',
    ]);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $item = eveningItems($preview)->first();

    expect($item)->not->toBeNull()
        ->and($item['status'])->toBe('open')
        ->and($item['confidence'])->toBe('low')
        ->and($item['uncertainty'])->toBe('Требуется уточнение.')
        ->and($item['evidence'][0]['role'])->toBe('question');
});

it('includes an unresolved prior-day problem as carry-over', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        sentAt: '2026-06-16 08:00:00',
        messageId: '301',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events_considered'])->toBe(1)
        ->and($preview['events_included'])->toBe(1)
        ->and($preview['events'][0]['carry_over'])->toBeTrue()
        ->and($preview['events'][0]['open_age_days'])->toBe(2)
        ->and($preview['no_material_events'])->toBeFalse()
        ->and($preview['mode'])->toBe([
            'read_only' => true,
            'telegram_actions' => 0,
            'mutations' => 0,
        ]);
});

it('orders the same preview deterministically', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Есть риск задержки с ключами',
        sentAt: '2026-06-17 09:00:00',
        messageId: '401',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        sentAt: '2026-06-17 08:00:00',
        messageId: '402',
        threadId: '12',
    ));

    $builder = app(TelegramEveningIntelligenceBuilder::class);

    expect($builder->build('2026-06-17'))->toBe($builder->build('2026-06-17'));
});

it('omits standalone requests from the management preview', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Проверьте, пожалуйста, квартиру',
        messageId: '501',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        messageId: '502',
        threadId: '12',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $items = eveningItems($preview)->unique('event_key')->values();

    expect($preview['events_considered'])->toBe(2)
        ->and($preview['events_included'])->toBe(1)
        ->and($preview['events_omitted'])->toBe(1)
        ->and($items)->toHaveCount(1)
        ->and($items->first()['types'])->not->toBe(['request']);
});

it('keeps every factual item traceable without exposing the raw payload', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        messageId: '601',
        raw: ['private_marker' => 'must-not-leak'],
    );
    app(TelegramOperationalEventObserver::class)->observe($message);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $item = eveningItems($preview)->first();

    expect($item['event_key'])->not->toBeEmpty()
        ->and($item['evidence'])->toHaveCount(1)
        ->and($item['evidence'][0])->toMatchArray([
            'local_message_id' => $message->id,
            'telegram_message_id' => '601',
            'role' => 'report',
            'transition' => 'created',
        ])
        ->and($item['evidence'][0]['occurred_at'])->not->toBeEmpty()
        ->and(json_encode($preview))->not->toContain('must-not-leak')
        ->not->toContain('"raw"');
});

it('omits an event whose source evidence message is unavailable', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        messageId: '701',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);
    $message->delete();

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events_considered'])->toBe(1)
        ->and($preview['events_included'])->toBe(0)
        ->and($preview['events_omitted'])->toBe(1)
        ->and($preview['sections'])->toBe([])
        ->and($preview['no_material_events'])->toBeTrue();
});

it('builds conservative management sections without cross-event grouping or scoring', function () {
    $observer = app(TelegramOperationalEventObserver::class);

    $recurringProblem = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        sentAt: '2026-06-17 08:00:00',
        messageId: '801',
        threadId: '21',
    );
    $recurringResult = $observer->observe($recurringProblem);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Замок исправили, всё работает',
        sentAt: '2026-06-17 09:00:00',
        messageId: '802',
        raw: ['message' => ['reply_to_message' => ['message_id' => 801]]],
        threadId: '21',
        userId: '102',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Замок снова не работает в квартире',
        sentAt: '2026-06-17 10:00:00',
        messageId: '803',
        threadId: '21',
        userId: '103',
    ));

    $resolvedProblem = TelegramOperationalTestDatabase::message(
        'Не работает второй замок в квартире',
        sentAt: '2026-06-17 08:10:00',
        messageId: '804',
        threadId: '22',
    );
    $resolvedResult = $observer->observe($resolvedProblem);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Второй замок исправили',
        sentAt: '2026-06-17 09:10:00',
        messageId: '805',
        raw: ['message' => ['reply_to_message' => ['message_id' => 804]]],
        threadId: '22',
        userId: '104',
    ));

    $observer->observe(TelegramOperationalTestDatabase::message(
        'В ванной грязно, качество уборки плохое',
        messageId: '806',
        threadId: '23',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Задержка выдачи ключей до вечера',
        messageId: '807',
        threadId: '24',
    ));
    $question = TelegramOperationalTestDatabase::message(
        'Когда будут ключи от квартиры?',
        messageId: '808',
        threadId: '25',
    );
    $observer->observe($question);
    $observer->observe($question, 'unanswered', Carbon::parse('2026-06-17 13:00:00', 'Europe/Rome'));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Спасибо, быстро помог с ключами',
        messageId: '809',
        threadId: '26',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Спасибо!',
        messageId: '810',
        threadId: '27',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $sections = collect($preview['sections'])->keyBy('key');
    $items = eveningItems($preview)->unique('event_key')->keyBy('event_key');
    $sectionEventKeys = collect($preview['sections'])->flatMap(fn (array $section) => collect($section['items'])->pluck('event_key'));

    expect($sections->keys()->all())->toContain('attention', 'resolved', 'quality', 'risks_delays', 'positive')
        ->not->toContain('tomorrow')
        ->and($items[$recurringResult['event_key']]['status'])->toBe('reopened')
        ->and($items[$recurringResult['event_key']]['repeated'])->toBeTrue()
        ->and($items[$resolvedResult['event_key']]['status'])->toBe('resolved')
        ->and($sectionEventKeys->duplicates())->toBeEmpty()
        ->and($items->contains(fn (array $item) => in_array('unanswered_question', $item['types'], true)))->toBeTrue()
        ->and($items->contains(fn (array $item) => in_array('positive_contribution', $item['types'], true)))->toBeTrue()
        ->and(json_encode($preview))->not->toContain('score')
        ->not->toContain('rating')
        ->not->toContain('employee');
});

it('caps each management section at seven items', function () {
    $observer = app(TelegramOperationalEventObserver::class);

    foreach (range(1, 9) as $index) {
        $observer->observe(TelegramOperationalTestDatabase::message(
            "Задержка выдачи ключей для квартиры {$index}",
            messageId: (string) (900 + $index),
            threadId: (string) (100 + $index),
        ));
    }

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $sections = collect($preview['sections'])->keyBy('key');

    expect($sections['risks_delays']['items'])->toHaveCount(7)
        ->and($preview['events_considered'])->toBe(9)
        ->and($preview['events_included'])->toBe(7)
        ->and($preview['events_omitted'])->toBe(2);
});

it('keeps generic resolutions and low confidence noise in technical events only', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message('Готово', messageId: '1001'));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Похоже, возможно проблема', messageId: '1002', threadId: '12',
    ));
    TelegramOperationalEventEvidence::query()
        ->whereHas('observation.message', fn ($query) => $query->where('message_id', '1002'))
        ->update(['confidence' => 'low', 'uncertainty' => 'Нужно подтверждение']);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events'])->not->toBeEmpty()
        ->and(eveningItems($preview))->toBeEmpty()
        ->and($preview['no_material_events'])->toBeTrue();
});

it('filters events by the configured external forum id', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает замок в Navigli', messageId: '1101', chatId: '-1001',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает замок в Lodi', messageId: '1102', chatId: '-1002',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17', [
        'district' => ['key' => 'navigli', 'label' => 'Navigli', 'chat_id' => '-1001'],
    ]);

    expect($preview['events_considered'])->toBe(1)
        ->and($preview['events'])->toHaveCount(1)
        ->and($preview['events'][0]['summary'])->toContain('Navigli');
});

it('keeps generic gratitude from a stale ledger in technical json only', function () {
    app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message('Спасибо, быстро помог с ключами', messageId: '1201'),
    );
    TelegramOperationalEvent::query()->update(['summary' => 'Спасибо и хорошего дня 🌺']);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events'])->toHaveCount(1)
        ->and($preview['events'][0]['summary'])->toBe('Спасибо и хорошего дня 🌺')
        ->and($preview['sections'])->toBe([])
        ->and($preview['no_material_events'])->toBeTrue();
});

it('keeps same-day open events in the daily section and carries yesterday events with an actionable control', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $yesterday = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 1.',
        sentAt: '2026-06-16 10:00:00',
        messageId: '901',
    );
    $today = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 2.',
        sentAt: '2026-06-17 10:00:00',
        messageId: '902',
        threadId: '12',
    );
    $observer->observe($yesterday);
    $observer->observe($today);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $items = collect($preview['events'])->keyBy('summary');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($items['Не работает свет в комнате 1.']['carry_over'])->toBeTrue()
        ->and($items['Не работает свет в комнате 1.']['open_age_days'])->toBe(2)
        ->and($items['Не работает свет в комнате 2.']['carry_over'])->toBeFalse()
        ->and($text)->toContain('За день:')
        ->toContain('• Не работает свет в комнате 2.')
        ->toContain('🔄 Переходящие проблемы:')
        ->toContain('• Не работает свет в комнате 1. Открыто со вчера.')
        ->toContain('• Проверить неисправность света в комнате 1.');
});

it('uses calendar-day age for an older open event without mutating ledger state', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 1.',
        sentAt: '2026-06-14 23:30:00',
        messageId: '911',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);
    $before = TelegramOperationalEvent::query()->firstOrFail()->toArray();

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($preview['events'][0]['open_age_days'])->toBe(4)
        ->and($text)->toContain('Открыто 4 дня.')
        ->and(TelegramOperationalEvent::query()->firstOrFail()->toArray())->toBe($before);
});

it('excludes an old resolution but shows an older problem resolved during the selected day only as resolved', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $old = TelegramOperationalTestDatabase::message('Не открывается дверь.', '2026-06-14 08:00:00', '921');
    $observer->observe($old);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Дверь открыли.', '2026-06-15 09:00:00', '922',
        ['message' => ['reply_to_message' => ['message_id' => 921]]],
    ));
    $today = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире.', '2026-06-16 08:00:00', '923', threadId: '12',
    );
    $observer->observe($today);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Замок исправили.', '2026-06-17 09:00:00', '924',
        ['message' => ['reply_to_message' => ['message_id' => 923]]],
        threadId: '12',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($preview['events'])->toHaveCount(1)
        ->and($preview['events'][0]['status'])->toBe('resolved')
        ->and($preview['events'][0]['carry_over'])->toBeFalse()
        ->and($text)->toContain('✅ Решено сегодня:')
        ->not->toContain('🔄 Переходящие проблемы:')
        ->not->toContain('Осталось на контроле:');
});

it('reconstructs a historical carry-over even when the event is currently resolved', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 1.', '2026-06-16 08:00:00', '931',
    );
    $observer->observe($root);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Свет исправили.', '2026-06-18 09:00:00', '932',
        ['message' => ['reply_to_message' => ['message_id' => 931]]],
    ));

    expect(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events'][0]['status'])->toBe('open')
        ->and($preview['events'][0]['carry_over'])->toBeTrue()
        ->and($preview['events'][0]['open_age_days'])->toBe(2);
});

it('starts carry-over age from the latest confirmed reopen transition', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message(
        'Не открывается дверь в квартире.', '2026-09-01 08:00:00', '941',
    );
    $observer->observe($root);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Дверь открыли.', '2026-09-02 08:00:00', '942',
        ['message' => ['reply_to_message' => ['message_id' => 941]]],
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Дверь снова не открывается.', '2026-09-20 08:00:00', '943',
        ['message' => ['reply_to_message' => ['message_id' => 941]]],
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-22');

    expect($preview['events'][0]['status'])->toBe('reopened')
        ->and($preview['events'][0]['carry_over'])->toBeTrue()
        ->and($preview['events'][0]['open_since'])->toStartWith('2026-09-20')
        ->and($preview['events'][0]['open_age_days'])->toBe(3);
});

it('uses the captured application clock as the current-day cutoff', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'Europe/Rome'));

    try {
        $observer = app(TelegramOperationalEventObserver::class);
        $observer->observe(TelegramOperationalTestDatabase::message(
            'Не работает свет в комнате 1.', '2026-06-17 10:00:00', '951',
        ));
        $observer->observe(TelegramOperationalTestDatabase::message(
            'Не работает свет в комнате 2.', '2026-06-17 14:00:00', '952', threadId: '12',
        ));

        $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

        expect($preview['events'])->toHaveCount(1)
            ->and($preview['events'][0]['summary'])->toBe('Не работает свет в комнате 1.');
    } finally {
        Carbon::setTestNow();
    }
});

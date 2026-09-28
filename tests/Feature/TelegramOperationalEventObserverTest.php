<?php

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(fn () => TelegramOperationalTestDatabase::refresh());
afterEach(fn () => TelegramOperationalTestDatabase::purge());

it('creates one traceable event and reuses an unchanged observation', function () {
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире');
    $observer = app(TelegramOperationalEventObserver::class);

    $first = $observer->observe($message, 'message', Carbon::parse('2026-06-17 08:00:00', 'Europe/Rome'));
    $second = $observer->observe($message->fresh(['chat', 'topic', 'telegramUser']), 'message', Carbon::parse('2026-06-17 08:01:00', 'Europe/Rome'));

    $event = TelegramOperationalEvent::query()->with('evidence.observation')->first();

    expect($first['outcome'])->toBe('created')
        ->and($first['event_key'])->toBe('telegram:-1001:1')
        ->and($second['idempotent_reuse'])->toBeTrue()
        ->and(TelegramOperationalEvent::query()->count())->toBe(1)
        ->and(TelegramOperationalObservation::query()->count())->toBe(1)
        ->and($event->evidence)->toHaveCount(1)
        ->and($event->evidence->first()->observation->telegram_message_id)->toBe($message->id);
});

it('stores explainable no-event decisions without an event', function () {
    $message = TelegramOperationalTestDatabase::message('Всем привет, хорошего дня');

    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    expect($result)->toMatchArray(['outcome' => 'no_event', 'reason_code' => 'ordinary_conversation'])
        ->and(TelegramOperationalEvent::query()->count())->toBe(0)
        ->and(TelegramOperationalObservation::query()->where('state', 'completed')->count())->toBe(1);
});

it('does not create an operational event for a connectivity explanation about message sending', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не могу тут к вай фаю подключиться, поэтому так отправляется 🥲',
    );

    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    expect($result)->toMatchArray([
        'outcome' => 'no_event',
        'reason_code' => 'communication_connectivity_chatter',
    ])->and(TelegramOperationalEvent::query()->count())->toBe(0);
});

it('rejects private bot service and empty messages', function (array $case) {
    $message = TelegramOperationalTestDatabase::message(
        text: $case['text'],
        messageId: (string) fake()->unique()->numberBetween(10, 9999),
        raw: $case['raw'],
        chatType: $case['type'] === 'private' ? 'private' : 'supergroup',
        messageType: $case['type'] === 'service' ? 'forum_topic_created' : 'text',
    );

    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    expect($result['outcome'])->toBe('no_event')
        ->and($result['reason_code'])->toBe($case['reason']);
})->with([
    'private' => [[
        'text' => 'Не работает замок', 'type' => 'private', 'raw' => [], 'reason' => 'private_or_disallowed_chat',
    ]],
    'bot' => [[
        'text' => 'Не работает замок', 'type' => 'bot', 'raw' => ['message' => ['from' => ['is_bot' => true]]], 'reason' => 'bot_or_service_message',
    ]],
    'service' => [[
        'text' => 'Создана тема', 'type' => 'service', 'raw' => [], 'reason' => 'unsupported_message_type',
    ]],
    'empty' => [[
        'text' => '', 'type' => 'empty', 'raw' => [], 'reason' => 'empty_content',
    ]],
]);

it('still rejects a synthetic supergroup excluded by an explicit test allowlist', function () {
    config(['services.telegram.operational_chat_ids' => ['-1002']]);

    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок',
        chatId: '-1001',
    );

    $result = app(TelegramOperationalEventObserver::class)->observe($message);

    expect($result)->toMatchArray([
        'outcome' => 'no_event',
        'reason_code' => 'private_or_disallowed_chat',
    ]);
});

it('supports legacy json raw data and retains low-confidence uncertainty', function () {
    $message = TelegramOperationalTestDatabase::message('Кажется, с замком может быть проблема');
    DB::connection('analytics')->table('telegram_messages')->where('id', $message->id)->update([
        'raw' => json_encode(json_encode($message->raw, JSON_UNESCAPED_UNICODE), JSON_UNESCAPED_UNICODE),
    ]);

    $result = app(TelegramOperationalEventObserver::class)->observe($message->fresh(['chat', 'topic', 'telegramUser']));

    expect($result['confidence'])->toBe('low')
        ->and($result['uncertainty'])->not->toBeNull();
});

it('keeps an operational question pending until four hours and matures it once', function () {
    $message = TelegramOperationalTestDatabase::message('Кто привезёт ключи в квартиру?', '2026-06-17 08:00:00');
    $observer = app(TelegramOperationalEventObserver::class);

    $initial = $observer->observe($message, 'message', Carbon::parse('2026-06-17 08:00:00', 'Europe/Rome'));
    $early = $observer->observe($message, 'unanswered', Carbon::parse('2026-06-17 11:59:59', 'Europe/Rome'));
    $due = $observer->observe($message, 'unanswered', Carbon::parse('2026-06-17 12:00:00', 'Europe/Rome'));
    $again = $observer->observe($message, 'unanswered', Carbon::parse('2026-06-17 12:01:00', 'Europe/Rome'));

    expect($initial['outcome'])->toBe('pending_question')
        ->and($initial['unanswered_due_at'])->toContain('2026-06-17T12:00:00')
        ->and($early['outcome'])->toBe('pending_question')
        ->and($due['event_types'])->toContain('unanswered_question')
        ->and($again['idempotent_reuse'])->toBeTrue();
});

it('tracks explicit updates resolution and recurrence as one lifecycle', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '100');
    $action = TelegramOperationalTestDatabase::message(
        'Уже проверяю дверь', '2026-06-17 08:10:00', '101',
        ['message' => ['reply_to_message' => ['message_id' => 100]]],
    );
    $resolution = TelegramOperationalTestDatabase::message(
        'Починили, всё готово', '2026-06-17 08:30:00', '102',
        ['message' => ['reply_to_message' => ['message_id' => 100]]],
    );
    $recurrence = TelegramOperationalTestDatabase::message('Замок снова не работает', '2026-06-18 09:00:00', '103');

    $created = $observer->observe($root);
    $updated = $observer->observe($action);
    $resolved = $observer->observe($resolution);
    $reopened = $observer->observe($recurrence);
    $event = TelegramOperationalEvent::query()->with(['evidence.observation'])->sole();

    expect($created['outcome'])->toBe('created')
        ->and($updated['outcome'])->toBe('updated')
        ->and($resolved['outcome'])->toBe('resolved')
        ->and($reopened['outcome'])->toBe('reopened')
        ->and($event->status)->toBe('reopened')
        ->and($event->types)->toContain('problem', 'action', 'resolution')
        ->and($event->evidence->pluck('transition')->all())->toBe(['created', 'updated', 'resolved', 'reopened'])
        ->and($event->evidence->pluck('observation.telegram_message_id')->all())
        ->toBe([$root->id, $action->id, $resolution->id, $recurrence->id]);
});

it('does not resolve when subject correlation has multiple candidates', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $first = TelegramOperationalTestDatabase::message('Не работает замок в квартире 1', '2026-06-17 08:00:00', '201');
    $second = TelegramOperationalTestDatabase::message('Замок в квартире грязный, требуется уборка', '2026-06-17 08:01:00', '202');
    $resolution = TelegramOperationalTestDatabase::message('Замок в квартире исправлен', '2026-06-17 08:20:00', '203');

    $observer->observe($first);
    $observer->observe($second);
    $result = $observer->observe($resolution);

    expect($result)->toMatchArray([
        'outcome' => 'no_event',
        'reason_code' => 'ambiguous_correlation',
        'confidence' => 'low',
    ])->and(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and(TelegramOperationalEvent::query()->where('status', 'resolved')->count())->toBe(0);
});

it('keeps matching situations in different topics separate', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $first = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире', '2026-06-17 08:00:00', '301', threadId: '11',
    );
    $second = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире', '2026-06-17 08:00:00', '302', threadId: '12',
    );

    $observer->observe($first);
    $observer->observe($second);

    expect(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and(TelegramOperationalEvent::query()->pluck('telegram_topic_id')->unique())->toHaveCount(2);
});

it('reconsiders an edited root and dismisses unsupported projection without deleting history', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $message = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '401');

    $observer->observe($message);
    $message->forceFill([
        'text' => 'Всем хорошего дня',
        'edited_at' => Carbon::parse('2026-06-17 08:05:00', 'Europe/Rome'),
        'raw' => ['edited_message' => [
            'message_id' => 401,
            'edit_date' => Carbon::parse('2026-06-17 08:05:00', 'Europe/Rome')->timestamp,
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 101, 'is_bot' => false],
            'text' => 'Всем хорошего дня',
        ]],
    ])->save();

    $result = $observer->observe($message->fresh(['chat', 'topic', 'telegramUser', 'attachments']));
    $event = TelegramOperationalEvent::query()->sole();

    expect($result['outcome'])->toBe('dismissed')
        ->and($event->status)->toBe('dismissed')
        ->and(TelegramOperationalObservation::query()->count())->toBe(2)
        ->and(TelegramOperationalEventEvidence::query()->count())->toBe(2)
        ->and(TelegramOperationalEventEvidence::query()->orderBy('id')->pluck('is_current_revision')->all())
        ->toBe([false, true]);
});

it('does not merge unrelated events through generic fallback or broad cleaning context', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $firstDelay = TelegramOperationalTestDatabase::message('Я чуть задержусь', '2026-06-17 08:00:00', '501');
    $secondDelay = TelegramOperationalTestDatabase::message('Я чуть задержусь', '2026-06-17 08:10:00', '502');
    $firstDefect = TelegramOperationalTestDatabase::message('На кухне грязная духовка', '2026-06-17 09:00:00', '503');
    $secondDefect = TelegramOperationalTestDatabase::message('В ванной грязная раковина', '2026-06-17 09:10:00', '504');

    $observer->observe($firstDelay);
    $observer->observe($secondDelay);
    $observer->observe($firstDefect);
    $observer->observe($secondDefect);

    expect(TelegramOperationalEvent::query()->count())->toBe(4)
        ->and(TelegramOperationalEvent::query()->withCount('evidence')->get()->pluck('evidence_count')->all())
        ->toBe([1, 1, 1, 1]);
});

it('treats short factual direct replies as answers without requiring an event classification', function (string $answer) {
    $observer = app(TelegramOperationalEventObserver::class);
    $question = TelegramOperationalTestDatabase::message('Во сколько заезд в квартиру?', '2026-06-17 08:00:00', '601');
    $reply = TelegramOperationalTestDatabase::message(
        $answer,
        '2026-06-17 08:30:00',
        '602',
        ['message' => ['reply_to_message' => ['message_id' => 601]]],
    );

    $observer->observe($question, 'message', '2026-06-17 08:00:00');
    $replyResult = $observer->observe($reply, 'message', '2026-06-17 08:30:00');
    $unanswered = $observer->observe($question, 'unanswered', '2026-06-17 12:00:00');

    expect($replyResult['outcome'])->toBe('no_event')
        ->and($unanswered)->toMatchArray([
            'outcome' => 'no_event',
            'reason_code' => 'operational_question_answered',
        ])->and(TelegramOperationalEvent::query()->sole()->types)->not->toContain('unanswered_question');
})->with([
    'yes' => ['Да'],
    'no' => ['Нет'],
    'time' => ['В 15:00'],
    'status' => ['Уже вышли'],
    'confirmation' => ['Проверено ✔️'],
]);

it('does not treat a direct follow-up question as an answer', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $question = TelegramOperationalTestDatabase::message('Во сколько заезд в квартиру?', '2026-06-17 08:00:00', '611');
    $followUp = TelegramOperationalTestDatabase::message(
        'А точнее во сколько?',
        '2026-06-17 08:30:00',
        '612',
        ['message' => ['reply_to_message' => ['message_id' => 611]]],
    );

    $observer->observe($question, 'message', '2026-06-17 08:00:00');
    $observer->observe($followUp, 'message', '2026-06-17 08:30:00');
    $unanswered = $observer->observe($question, 'unanswered', '2026-06-17 12:00:00');

    expect($unanswered['event_types'])->toContain('unanswered_question');
});

it('resolves an access problem from a same-topic factual continuation', function (string $problem, string $answer) {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message($problem, '2026-06-17 08:00:00', '701');
    $observer->observe($root);
    $reply = TelegramOperationalTestDatabase::message($answer, '2026-06-17 08:10:00', '702');

    expect($observer->observe($reply)['outcome'])->toBe('resolved')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved')
        ->and(TelegramOperationalEventEvidence::query()->latest('id')->value('transition'))->toBe('resolved');
})->with([
    'opened after PM' => ['Дверь не открывается', 'Спасибо открыла'],
    'entered after concierge was missing' => ['Стою здесь, консьержа нет. Не открывают.', 'Зашла, люди вышли'],
]);

it('keeps a completed replacement as a historical defect without leaving its action active', function () {
    $message = TelegramOperationalTestDatabase::message('Бракованная наволочка, заменила.');
    $result = app(TelegramOperationalEventObserver::class)->observe($message);
    $event = TelegramOperationalEvent::query()->with('evidence')->sole();
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $previewEvent = collect($preview['events'])->sole();

    expect($result['outcome'])->toBe('resolved')
        ->and($event->status)->toBe('resolved')
        ->and($event->primary_type)->toBe('quality_issue')
        ->and($event->evidence->pluck('role')->all())->toBe(['resolution'])
        ->and($event->evidence->pluck('transition')->all())->toBe(['resolved'])
        ->and($event->evidence->pluck('status_after')->all())->toBe(['resolved'])
        ->and($previewEvent['editorial']['state'])->toBe('completed')
        ->and($previewEvent['editorial']['needs_attention'])->toBeFalse()
        ->and($previewEvent['editorial']['next_action'])->toBeNull()
        ->and(collect($preview['editorial_sections'])->pluck('key'))->toContain('resolved');
});

it('does not create standalone events for context-free completion words', function (string $text) {
    $result = app(TelegramOperationalEventObserver::class)->observe(
        TelegramOperationalTestDatabase::message($text),
    );

    expect($result['outcome'])->toBe('no_event')
        ->and(TelegramOperationalEvent::query()->count())->toBe(0);
})->with([
    'готово' => ['Готово.'],
    'поменяла' => ['Поменяла.'],
]);

it('suppresses general instructions and reassurance while retaining concrete delay and courier facts', function (string $text, string $expected) {
    $result = app(TelegramOperationalEventObserver::class)->observe(TelegramOperationalTestDatabase::message($text));

    expect($result['outcome'])->toBe($expected);
})->with([
    'drain procedure' => ['Вот как разбираются такие сливы: сначала снимаем решётку и чистим.', 'no_event'],
    'photo procedure' => ['#сильныйбардак делаем 10-15 фото каждой зоны по инструкции.', 'no_event'],
    'reassurance' => ['Да, думаю не проблема будет', 'no_event'],
    'delay' => ['Мне 10 мин еще нужно', 'created'],
    'courier did not take dirty linen' => ['Курьер не забрал грязное бельё', 'created'],
]);

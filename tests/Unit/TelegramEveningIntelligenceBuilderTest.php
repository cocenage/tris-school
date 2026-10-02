<?php

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    TelegramOperationalTestDatabase::refresh();
    $GLOBALS['telegram_evening_test_created_apartments_table'] = ! Schema::hasTable('apartments');

    if ($GLOBALS['telegram_evening_test_created_apartments_table']) {
        Schema::create('apartments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
});
afterEach(function (): void {
    TelegramOperationalTestDatabase::purge();

    if ($GLOBALS['telegram_evening_test_created_apartments_table'] ?? false) {
        Schema::dropIfExists('apartments');
    }

    unset($GLOBALS['telegram_evening_test_created_apartments_table']);
});

it('bounds every human section to requested-day evidence while retaining historical ledger projections', function (?string $district, bool $withCurrent): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 22:00:00', 'Europe/Rome'));

    try {
        $observer = app(TelegramOperationalEventObserver::class);
        foreach ([
            ['2026-09-19', 'Простынь с пятном.', 'quality_issue'],
            ['2026-09-24', 'Не работает переключатель.', 'problem'],
            ['2026-09-28', 'Брак наволочки.', 'quality_issue'],
            ['2026-09-29', 'Не работает свет в комнате 1.', 'problem'],
        ] as $index => [$date, $summary, $type]) {
            $message = TelegramOperationalTestDatabase::message(
                'Не работает свет в комнате 1.',
                sentAt: $date.' 09:00:00',
                messageId: (string) (3000 + $index),
                threadId: (string) (3000 + $index),
            );
            $result = $observer->observe($message);
            $message->update(['text' => $summary]);
            TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->update([
                'primary_type' => $type,
                'types' => [$type],
                'summary' => $summary,
            ]);
        }
        $currentKey = null;
        if ($withCurrent) {
            $current = TelegramOperationalTestDatabase::message(
                'Не работает свет в комнате 2.',
                sentAt: '2026-09-30 10:00:00',
                messageId: '3010',
                threadId: '3010',
            );
            $currentKey = $observer->observe($current)['event_key'];
        }
        $before = TelegramOperationalEvent::query()->orderBy('id')->get()->toArray();
        $evidenceBefore = TelegramOperationalEventEvidence::query()->orderBy('id')->get()->toArray();
        $options = $district === null ? [] : ['district' => ['label' => $district, 'chat_id' => '-1001']];
        $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-30', $options);
        $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);
        $historical = collect($preview['events'])->filter(fn (array $item): bool => ! $item['editorial']['relevant_today']);
        $editorialSections = collect($preview['editorial_sections'])->keyBy('key');
        $humanKeys = collect($preview['editorial_sections'])->flatMap(fn (array $section) => $section['items'])->pluck('event_key')->unique()->values()->all();
        $dayText = str($text)->before('⚠️ Осталось с прошлых дней:')->toString();
        $carryOverItems = collect($editorialSections->get('carry_over')['items'] ?? []);

        expect($historical)->toHaveCount(4)
            ->and($historical->every(fn (array $item): bool => $item['status'] === 'open' && $item['carry_over'] && $item['editorial']['state'] === 'carry_over' && ! $item['editorial']['needs_attention'] && $item['editorial']['next_action'] === null))->toBeTrue()
            ->and($withCurrent ? in_array($currentKey, $humanKeys, true) : $carryOverItems->isNotEmpty())->toBeTrue()
            ->and($carryOverItems)->toHaveCount(4)
            ->and(eveningItems($preview)->pluck('event_key')->unique()->values()->all())->toBe($withCurrent ? [$currentKey] : [])
            ->and($preview['events_included'])->toBe($withCurrent ? 1 : 0)
            ->and($preview['current_events_included'])->toBe($withCurrent ? 1 : 0)
            ->and($preview['carry_over_events_included'])->toBe(0)
            ->and($preview['no_material_events'])->toBe(! $withCurrent)
            ->and($text)->toContain('🌙 '.($district ?? 'TRIS').' — проблемы за день · 30.09.2026')
            ->and($dayText)->not->toContain('Простынь', 'переключатель', 'наволочк', 'комнате 1')
            ->and($text)->not->toContain('⚠️ Осталось с прошлых дней:', 'с 19.09', 'с 24.09', 'с 28.09')
            ->and(TelegramOperationalEvent::query()->orderBy('id')->get()->toArray())->toBe($before)
            ->and(TelegramOperationalEventEvidence::query()->orderBy('id')->get()->toArray())->toBe($evidenceBefore);

        if ($withCurrent) {
            $sections = collect($preview['editorial_sections'])->keyBy('key');
            expect($sections->keys()->all())->toContain('day', 'attention', 'actions')
                ->and($preview['daily_problems'])->toHaveCount(1)
                ->and($text)->toContain('Не работает свет в комнате 2.')
                ->not->toContain('Проверить неисправность света в комнате 2.');
        } else {
            expect($text)->toContain('✅ Незакрытых проблем за день не зафиксировано.')
                ->not->toContain('⚠️ Осталось с прошлых дней:');
        }
    } finally {
        Carbon::setTestNow();
    }
})->with([[null], ['Navigli'], ['Lodi'], ['Como'], ['Certosa'], ['Lambrate']])->with([false, true]);

function eveningItems(array $preview): Collection
{
    return collect($preview['sections'])->flatMap(fn (array $section) => $section['items']);
}

function recurrenceProblem(string $messageId, string $sentAt, string $threadId, string $text = 'Не работает замок в квартире', string $chatId = '-1001', ?int $apartmentId = 77): array
{
    $result = app(TelegramOperationalEventObserver::class)->observe(TelegramOperationalTestDatabase::message(
        $text,
        sentAt: $sentAt,
        messageId: $messageId,
        threadId: $threadId,
        chatId: $chatId,
        userId: 'worker-'.$messageId,
    ));

    if ($result['event_key'] ?? null) {
        TelegramOperationalEvent::query()
            ->where('event_key', $result['event_key'])
            ->update(['apartment_id' => $apartmentId]);

        if ($apartmentId !== null) {
            DB::table('apartments')->updateOrInsert(
                ['id' => $apartmentId],
                ['name' => 'Via Test '.$apartmentId],
            );
        }
    }

    return $result;
}

it('retains recurrence data internally but hides it from the human evening digest', function () {
    recurrenceProblem('rec-1', '2026-06-17 08:00:00', '31');
    recurrenceProblem('rec-2', '2026-06-19 08:00:00', '32');
    recurrenceProblem('rec-3', '2026-06-22 08:00:00', '33');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');
    $recurrencesBeforeFormatting = $preview['recurrences'];
    $rendered = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($preview['recurrences'])->toHaveCount(1)
        ->and($preview['recurrences'][0])->toMatchArray([
            'location_key' => 'apartment:77',
            'primary_type' => 'problem',
            'family' => 'access_lock',
            'count' => 3,
            'window_start' => '2026-06-16',
        ])
        ->and($rendered)->not->toContain('⚠️ Повторяется:')
        ->and($preview['recurrences'])->toBe($recurrencesBeforeFormatting);
});

it('does not show recurrence below threshold or when the third occurrence is outside the window', function () {
    recurrenceProblem('rec-11', '2026-06-17 08:00:00', '41');
    recurrenceProblem('rec-12', '2026-06-22 08:00:00', '42');
    $two = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    recurrenceProblem('rec-13', '2026-06-15 08:00:00', '43');
    $outside = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    expect($two['recurrences'])->toBeEmpty()
        ->and($outside['recurrences'])->toBeEmpty()
        ->and(app(TelegramDigestFormatter::class)->eveningIntelligence($two))->not->toContain('⚠️ Повторяется:');
});

it('counts a created event once despite updates and evidence and counts an explicit resolved reopen as a new occurrence', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '921', threadId: '51', userId: 'worker-root');
    $created = $observer->observe($root);
    $event = TelegramOperationalEvent::query()->where('event_key', $created['event_key'])->firstOrFail();
    $event->update(['apartment_id' => 77]);
    $updateMessage = TelegramOperationalTestDatabase::message('Замок всё ещё не работает', '2026-06-18 08:00:00', '922', threadId: '51');
    $updateObservation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $updateMessage->id,
        'source_revision_hash' => str_repeat('a', 64),
        'evaluation_kind' => 'message', 'state' => 'completed', 'outcome' => 'event_updated',
        'reason_code' => 'operational_problem', 'confidence' => 'high', 'is_current_revision' => true,
        'processed_at' => '2026-06-18 08:00:00',
    ]);
    $event->evidence()->create([
        'observation_id' => $updateObservation->id,
        'role' => 'report', 'transition' => 'updated', 'status_before' => 'open', 'status_after' => 'open',
        'confidence' => 'high', 'occurred_at' => '2026-06-18 08:00:00', 'is_current_revision' => true,
    ]);
    recurrenceProblem('rec-23', '2026-06-19 08:00:00', '52');
    recurrenceProblem('rec-24', '2026-06-22 08:00:00', '53');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    expect($preview['recurrences'])->toHaveCount(1)
        ->and($preview['recurrences'][0]['count'])->toBe(3)
        ->and($preview['recurrences'][0]['occurrences'])->toHaveCount(3);
});

it('uses explicit reopen transitions as occurrences without letting resolution erase earlier occurrences', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не работает замок в квартире', '2026-06-17 08:00:00', '931', threadId: '61');
    $created = $observer->observe($root);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Замок починили, теперь открывается', '2026-06-18 08:00:00', '932',
        ['message' => ['reply_to_message' => ['message_id' => 931]]], threadId: '61', userId: 'resolution-user',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Замок снова сломан', '2026-06-19 08:00:00', '933', threadId: '61', userId: 'reopen-user',
    ));
    TelegramOperationalEvent::query()->where('event_key', $created['event_key'])->update(['apartment_id' => 77]);
    recurrenceProblem('rec-34', '2026-06-20 08:00:00', '62');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    expect($preview['recurrences'])->toHaveCount(1)
        ->and($preview['recurrences'][0]['count'])->toBe(3)
        ->and(collect($preview['recurrences'][0]['occurrences'])->pluck('transition')->all())->toContain('reopened');
});

it('keeps recurrence identities separate by apartment, problem type, and chat-topic identity and excludes non-durable types', function () {
    recurrenceProblem('rec-41', '2026-06-17 08:00:00', '71', apartmentId: 77);
    recurrenceProblem('rec-42', '2026-06-19 08:00:00', '72', apartmentId: 77);
    recurrenceProblem('rec-43', '2026-06-22 08:00:00', '73', apartmentId: 79);
    recurrenceProblem('rec-keys', '2026-06-20 08:30:00', 'keys-topic', 'Потеряли ключи от квартиры', apartmentId: 77);
    recurrenceProblem('rec-44', '2026-06-17 09:00:00', '74', 'Не работает подсветка вытяжки', apartmentId: 77);
    recurrenceProblem('rec-45', '2026-06-19 09:00:00', '75', 'Не работает подсветка вытяжки', apartmentId: 77);
    recurrenceProblem('rec-47', '2026-06-17 10:00:00', '77', apartmentId: null);
    recurrenceProblem('rec-48', '2026-06-19 10:00:00', '77', apartmentId: null, chatId: '-1002');
    recurrenceProblem('rec-49', '2026-06-22 10:00:00', '77', apartmentId: null, chatId: '-1003');

    foreach ([
        ['Задержка выдачи ключей до вечера', 'delay', 'rec-50'],
        ['Во сколько заезд?', 'request', 'rec-51'],
        ['Анна заметила дефект до заезда и сразу сообщила.', 'positive_contribution', 'rec-52'],
    ] as [$text, $type, $messageId]) {
        $result = recurrenceProblem($messageId, '2026-06-18 11:00:00', $messageId, $text);
        expect($result['event_types'])->toContain($type);
    }

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    expect($preview['recurrences'])->toBeEmpty();
});

it('excludes future occurrences from historical previews and performs no ledger mutations', function () {
    recurrenceProblem('rec-61', '2026-06-17 08:00:00', '81');
    recurrenceProblem('rec-62', '2026-06-19 08:00:00', '82');
    recurrenceProblem('rec-63', '2026-06-23 08:00:00', '83');
    $eventCount = TelegramOperationalEvent::query()->count();
    $evidenceCount = TelegramOperationalEventEvidence::query()->count();

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-22');

    expect($preview['recurrences'])->toBeEmpty()
        ->and($preview['mode']['mutations'])->toBe(0)
        ->and($preview['mode']['telegram_actions'])->toBe(0)
        ->and(TelegramOperationalEvent::query()->count())->toBe($eventCount)
        ->and(TelegramOperationalEventEvidence::query()->count())->toBe($evidenceCount);
});

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

it('retains an eligible prior-day problem for audit but excludes it from the daily report', function () {
    $message = TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире',
        sentAt: '2026-06-16 08:00:00',
        messageId: '301',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($preview['events_considered'])->toBe(1)
        ->and($preview['events_included'])->toBe(0)
        ->and($preview['current_events_included'])->toBe(0)
        ->and($preview['carry_over_events_included'])->toBe(0)
        ->and($preview['events'][0]['carry_over'])->toBeTrue()
        ->and($preview['events'][0]['open_age_days'])->toBe(2)
        ->and($preview['no_material_events'])->toBeTrue()
        ->and($preview['sections'])->toBeEmpty()
        ->and(collect($preview['editorial_sections'])->keyBy('key')->get('carry_over')['items'])->toHaveCount(1)
        ->and(app(TelegramDigestFormatter::class)->eveningIntelligence($preview))
        ->toContain('✅ Незакрытых проблем за день не зафиксировано.')
        ->not->toContain('⚠️ Осталось с прошлых дней:')
        ->and($preview['mode'])->toBe([
            'read_only' => true,
            'telegram_actions' => 0,
            'mutations' => 0,
        ]);
});

it('retains only durable prior-day issues in audit carry-over and hides them from the daily report', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $cases = [
        ['Я задержусь на 10 минут.', 'delay', false],
        ['Во сколько здесь заезд?', 'request', false],
        ['И фото загрязнений пожалуйста.', 'quality_issue', false],
        ['Оформи пожалуйста запрос на грязную квартиру.', 'quality_issue', false],
        ['Анна заметила дефект до заезда и сразу сообщила.', 'positive_contribution', false],
        ['Не открывается дверь в квартиру.', 'problem', true],
        ['Курьер не забрал грязное бельё.', 'quality_issue', true],
        ['Коврик брак.', 'quality_issue', true],
    ];

    foreach ($cases as $index => [$text, $type]) {
        $message = TelegramOperationalTestDatabase::message(
            $text,
            '2026-06-16 10:00:00',
            (string) (1100 + $index),
            threadId: (string) (1100 + $index),
        );
        $result = $observer->observe($message);

        expect($result['event_types'])->toContain($type);
    }

    $builder = app(TelegramEveningIntelligenceBuilder::class);
    $sameDay = $builder->build('2026-06-16');
    $sameDayText = app(TelegramDigestFormatter::class)->eveningIntelligence($sameDay);
    $followingDay = $builder->build('2026-06-17');
    $bySummary = collect($followingDay['events'])->keyBy('summary');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($followingDay);

    expect(collect($sameDay['events'])->pluck('summary'))->toContain('Задержка примерно на 10 минут.', 'Во сколько здесь заезд?')
        ->and($sameDayText)->toContain('Сотрудник сообщил о задержке примерно на 10 минут.')
        ->not->toContain('Уточняли время заезда.')
        ->and($bySummary->keys()->all())->toEqualCanonicalizing([
            'Не открывается дверь в квартиру.',
            'Курьер не забрал грязное бельё.',
            'Коврик брак.',
        ])
        ->and($bySummary->every(fn (array $item): bool => $item['carry_over'] && $item['open_age_days'] === 2))->toBeTrue()
        ->and(collect($followingDay['editorial_sections'])->keyBy('key')->get('carry_over')['items'])->toHaveCount(3)
        ->and($text)->toContain('✅ Незакрытых проблем за день не зафиксировано.')
        ->not->toContain('⚠️ Осталось с прошлых дней:')
        ->not->toContain('🔄 Требует внимания:')
        ->not->toContain('задержке')
        ->not->toContain('время заезда')
        ->not->toContain('фото загрязнений')
        ->not->toContain('Оформи пожалуйста');
});

it('keeps matured problem-classified questions available the same day but excludes them from later carry-over', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $questions = [
        ['Здесь 2 или 3 запасных бумаги не могу найти?', '1801', '181'],
        ['Очки сломаны выбрасывать?', '1802', '182'],
    ];

    foreach ($questions as [$text, $messageId, $threadId]) {
        $message = TelegramOperationalTestDatabase::message(
            $text,
            '2026-06-16 08:00:00',
            $messageId,
            threadId: $threadId,
        );
        $observer->observe($message, 'message', Carbon::parse('2026-06-16 08:00:00', 'Europe/Rome'));
        $observer->observe($message, 'unanswered', Carbon::parse('2026-06-16 12:00:00', 'Europe/Rome'));
    }

    $eventsBefore = TelegramOperationalEvent::query()->count();
    $evidenceBefore = TelegramOperationalEventEvidence::query()->count();
    $sameDay = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-16');
    $followingDay = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $sameDayText = app(TelegramDigestFormatter::class)->eveningIntelligence($sameDay);
    $followingDayText = app(TelegramDigestFormatter::class)->eveningIntelligence($followingDay);

    expect(collect($sameDay['events'])->whereIn('summary', collect($questions)->pluck(0))->count())->toBe(2)
        ->and(collect($sameDay['events'])->every(fn (array $item): bool => $item['carry_over'] === false))->toBeTrue()
        ->and($sameDayText)->not->toContain('Уточняли наличие запасной бумаги.', 'Уточняли, что делать со сломанными очками.')
        ->and($followingDay['events'])->toBeEmpty()
        ->and($followingDayText)->not->toContain('Открыто 2 дня.')
        ->not->toContain('Уточняли наличие запасной бумаги.')
        ->not->toContain('Уточняли, что делать со сломанными очками.')
        ->and(TelegramOperationalEvent::query()->count())->toBe($eventsBefore)
        ->and(TelegramOperationalEventEvidence::query()->count())->toBe($evidenceBefore)
        ->and($sameDay['mode']['mutations'])->toBe(0)
        ->and($followingDay['mode']['mutations'])->toBe(0);
});

it('allows a question-classified problem to carry over after independent evidence confirms a durable defect', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $question = TelegramOperationalTestDatabase::message(
        'У вытяжки сломана подсветка?',
        '2026-06-16 08:00:00',
        '1811',
        threadId: '191',
    );
    $created = $observer->observe($question, 'message', Carbon::parse('2026-06-16 08:00:00', 'Europe/Rome'));
    $observer->observe($question, 'unanswered', Carbon::parse('2026-06-16 12:00:00', 'Europe/Rome'));
    $confirmation = TelegramOperationalTestDatabase::message(
        'У вытяжки не работает подсветка.',
        '2026-06-16 13:00:00',
        '1812',
        threadId: '191',
    );
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $confirmation->id,
        'source_revision_hash' => str_repeat('b', 64),
        'evaluation_kind' => 'message',
        'state' => 'completed',
        'outcome' => 'evidence',
        'reason_code' => 'operational_problem',
        'confidence' => 'high',
        'is_current_revision' => true,
        'processed_at' => '2026-06-16 13:00:00',
    ]);
    $event = TelegramOperationalEvent::query()->where('event_key', $created['event_key'])->firstOrFail();
    $event->evidence()->create([
        'observation_id' => $observation->id,
        'role' => 'report',
        'transition' => 'evidence',
        'status_before' => 'open',
        'status_after' => 'open',
        'confidence' => 'high',
        'occurred_at' => '2026-06-16 13:00:00',
        'is_current_revision' => true,
    ]);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $item = collect($preview['events'])->firstWhere('event_key', $created['event_key']);

    expect($item)->not->toBeNull()
        ->and($item['carry_over'])->toBeTrue()
        ->and(collect($item['evidence'])->pluck('local_message_id'))->toContain($question->id, $confirmation->id);
});

it('shows a temporary missing-paper search only on its day unless independent stock evidence confirms a durable issue', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $search = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 1.',
        '2026-06-16 08:00:00',
        '1821',
        threadId: '192',
    );
    $created = $observer->observe($search, 'message', Carbon::parse('2026-06-16 08:00:00', 'Europe/Rome'));
    $event = TelegramOperationalEvent::query()->where('event_key', $created['event_key'])->firstOrFail();
    $search->update(['text' => 'Туалетную бумагу не могу найти.']);
    $event->update(['primary_type' => 'problem', 'types' => ['problem'], 'summary' => $search->text, 'status' => 'open']);
    $event->evidence()->update(['role' => 'report']);

    $missingItem = TelegramOperationalTestDatabase::message(
        'Не работает свет в комнате 1.',
        '2026-06-16 08:30:00',
        '1823',
        threadId: '193',
    );
    $missingItemResult = $observer->observe($missingItem, 'message', Carbon::parse('2026-06-16 08:30:00', 'Europe/Rome'));
    $missingItemEvent = TelegramOperationalEvent::query()->where('event_key', $missingItemResult['event_key'])->firstOrFail();
    $missingItem->update(['text' => 'Не могу найти фен.']);
    $missingItemEvent->update([
        'primary_type' => 'problem',
        'types' => ['problem'],
        'summary' => $missingItem->text,
        'status' => 'open',
    ]);
    $missingItemEvent->evidence()->update(['role' => 'report']);

    $builder = app(TelegramEveningIntelligenceBuilder::class);
    $sameDay = $builder->build('2026-06-16');
    $nextDayWithoutConfirmation = $builder->build('2026-06-17');
    $sameDayText = app(TelegramDigestFormatter::class)->eveningIntelligence($sameDay);
    $nextDayWithoutConfirmationText = app(TelegramDigestFormatter::class)->eveningIntelligence($nextDayWithoutConfirmation);

    $confirmation = TelegramOperationalTestDatabase::message(
        'Туалетной бумаги действительно нет, нужно пополнить запас.',
        '2026-06-16 13:00:00',
        '1822',
        threadId: '192',
    );
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $confirmation->id,
        'source_revision_hash' => str_repeat('c', 64),
        'evaluation_kind' => 'message',
        'state' => 'completed',
        'outcome' => 'evidence',
        'reason_code' => 'operational_problem',
        'confidence' => 'high',
        'is_current_revision' => true,
        'processed_at' => '2026-06-16 13:00:00',
    ]);
    $event->evidence()->create([
        'observation_id' => $observation->id,
        'role' => 'report',
        'transition' => 'evidence',
        'status_before' => 'open',
        'status_after' => 'open',
        'confidence' => 'high',
        'occurred_at' => '2026-06-16 13:00:00',
        'is_current_revision' => true,
    ]);

    $nextDayWithConfirmation = $builder->build('2026-06-17');
    $nextDayWithConfirmationText = app(TelegramDigestFormatter::class)->eveningIntelligence($nextDayWithConfirmation);

    expect($sameDayText)->not->toContain('Туалетную бумагу не могу найти.', 'Не могу найти фен.')
        ->and($nextDayWithoutConfirmation['events'])->toBeEmpty()
        ->and($nextDayWithoutConfirmationText)->not->toContain('Туалетную бумагу не могу найти.')
        ->not->toContain('Не могу найти фен.')
        ->and($nextDayWithConfirmation['events'])->toHaveCount(1)
        ->and($nextDayWithConfirmation['events'][0]['carry_over'])->toBeTrue()
        ->and(collect($nextDayWithConfirmation['editorial_sections'])->keyBy('key')->get('carry_over')['items'])->toHaveCount(1)
        ->and($nextDayWithConfirmationText)->not->toContain('🔄 Требует внимания:')
        ->toContain('✅ Незакрытых проблем за день не зафиксировано.')
        ->and($nextDayWithConfirmation['mode']['mutations'])->toBe(0)
        ->and($nextDayWithConfirmation['mode']['telegram_actions'])->toBe(0);
});

it('renders raw-safe switch failures, composes pronouns only from linked evidence, and omits context-dependent fragments', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $seed = function (string $summary, string $evidenceText, string $messageId, string $threadId) use ($observer): void {
        $message = TelegramOperationalTestDatabase::message(
            'Не работает свет в комнате 1.',
            '2026-06-16 09:00:00',
            $messageId,
            threadId: $threadId,
        );
        $result = $observer->observe($message, 'message', Carbon::parse('2026-06-16 09:00:00', 'Europe/Rome'));
        $event = TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->firstOrFail();
        $message->update(['text' => $evidenceText]);
        $event->update(['primary_type' => 'problem', 'types' => ['problem'], 'summary' => $summary, 'status' => 'open']);
    };

    $seed(
        'Он может поэтому и не работает, потому что уже включен режим был.',
        'Он может поэтому и не работает, потому что уже включен режим был.',
        '1831',
        '193',
    );
    $seed(
        'Вытяжка включена. Он может поэтому и не работает, потому что уже включен режим был.',
        'Вытяжка включена. Он может поэтому и не работает, потому что уже включен режим был.',
        '1834',
        '196',
    );
    $seed(
        'Переключатель не работает, поэтому должен прийти мастер.',
        'Переключатель не работает, поэтому должен прийти мастер.',
        '1832',
        '194',
    );
    $seed('Он не работает.', 'У вытяжки не работает свет.', '1833', '195');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-16');
    $rendered = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($rendered)->toContain('Не работает переключатель, требуется мастер.')
        ->toContain('У вытяжки не работает свет.')
        ->not->toContain('Он может поэтому и не работает')
        ->not->toContain('Вытяжка включена.')
        ->not->toContain('Он не работает.')
        ->not->toContain('Значимых операционных событий не зафиксировано.');
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
        ->and($preview['current_events_included'])->toBe(1)
        ->and($preview['carry_over_events_included'])->toBe(0)
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
        ->and($preview['current_events_included'])->toBe(0)
        ->and($preview['carry_over_events_included'])->toBe(0)
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
        ->and($preview['events_included'])->toBe(9)
        ->and($preview['events_omitted'])->toBe(0);
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

it('keeps current-day attention separate from carry-over items', function () {
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
        ->and($text)->toContain('Не работает свет в комнате 2.')
        ->not->toContain('Не работает свет в комнате 1.', '⚠️ Осталось с прошлых дней:', 'Осталось сделать:')
        ->and($preview['daily_problems'])->toHaveCount(1);
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
        ->and(collect($preview['editorial_sections'])->keyBy('key')->get('carry_over')['items'])->toHaveCount(1)
        ->and($text)->not->toContain('🔄 Требует внимания:')
        ->toContain('✅ Незакрытых проблем за день не зафиксировано.')
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
        ->and($text)->toContain('✅ Незакрытых проблем за день не зафиксировано.')
        ->not->toContain('✅ Решено сегодня:')
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

it('filters legacy unusable and contextless events from the final built and formatted digest', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-23 22:00:00', 'Europe/Rome'));

    try {
        $observer = app(TelegramOperationalEventObserver::class);
        $legacyEvent = function (string $summary, string $sentAt, string $messageId, string $threadId, ?string $subjectKey = null, string $chatId = '-1001') use ($observer): void {
            $message = TelegramOperationalTestDatabase::message(
                'Не работает свет в комнате 1.',
                sentAt: $sentAt,
                messageId: $messageId,
                threadId: $threadId,
                chatId: $chatId,
            );
            $result = $observer->observe($message);
            $event = TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->firstOrFail();

            $message->update(['text' => $summary]);
            $event->update([
                'primary_type' => 'problem',
                'types' => ['problem'],
                'summary' => $summary,
                'subject_key' => $subjectKey,
                'status' => 'open',
            ]);
        };

        $legacyEvent('Не могу тут к вай фаю подключиться, поэтому так отправляется 🥲', '2026-09-23 08:00:00', '2301', '2301', 'keys');
        $legacyEvent('Не работает.', '2026-09-23 08:05:00', '2302', '2302');
        $legacyEvent('Он давно не работает.', '2026-09-23 08:10:00', '2303', '2303');
        $legacyEvent('Сфоткать не могу гости на диване.', '2026-09-15 08:00:00', '2304', '2304');
        $legacyEvent('Не могу дозвониться.', '2026-09-22 08:00:00', '2305', '2305');
        $legacyEvent('У вытяжки не работает свет.', '2026-09-23 08:15:00', '2306', '2306', chatId: '-1009');
        $legacyEvent('Жалюзи упала, не могу повесить — очень высоко.', '2026-09-23 08:25:00', '2308', '2308', chatId: '-1008');
        $legacyEvent('Обнаружен брак маленького полотенца, замены нет.', '2026-09-23 08:35:00', '2310', '2310');
        $legacyEvent('У вытяжки не работает свет.', '2026-09-22 08:00:00', '2318', '2318');
        $legacyEvent('Простынь большая, жёлтое пятно; заменила, брак.', '2026-09-22 08:05:00', '2319', '2319');
        $legacyEvent('Сломана вешалка.', '2026-09-22 08:10:00', '2320', '2320');
        $legacyEvent('Не работает свет.', '2026-09-22 08:15:00', '2321', '2321');
        $legacyEvent('На кухне вытяжка не работает.', '2026-09-22 08:20:00', '2322', '2322');
        $legacyEvent('В программе гостевой локер: ошибка, правильный код — 1291.', '2026-09-22 08:25:00', '2323', '2323');
        $legacyEvent('Курьер забрал не всё грязное бельё.', '2026-09-21 07:55:00', '2324', '2324');
        $legacyEvent('Дверь закрыта, никто не открывает.', '2026-09-21 08:00:00', '2325', '2325');
        $legacyEvent('Обнаружена грязная посуда.', '2026-09-21 08:05:00', '2326', '2326');
        $legacyEvent('Не могу найти запасную бумагу.', '2026-09-21 08:10:00', '2327', '2327');

        recurrenceProblem('2331', '2026-09-17 08:00:00', '2331');
        recurrenceProblem('2332', '2026-09-19 08:00:00', '2332');
        recurrenceProblem('2333', '2026-09-21 08:00:00', '2333');

        $builder = app(TelegramEveningIntelligenceBuilder::class);
        $formatter = app(TelegramDigestFormatter::class);
        $preview = $builder->build('2026-09-23');
        $recurrencesBeforeFormatting = $preview['recurrences'];
        $renderedToday = $formatter->eveningIntelligence($preview);
        $renderedRawSafeOnly = $formatter->eveningIntelligence($builder->build('2026-09-23', [
            'district' => ['chat_id' => '-1008', 'label' => 'Navigli'],
        ]));
        $renderedComposedOnly = $formatter->eveningIntelligence($builder->build('2026-09-23', [
            'district' => ['chat_id' => '-1009', 'label' => 'Navigli'],
        ]));
        $previewPreviousDay = $builder->build('2026-09-22');
        $renderedPreviousDay = $formatter->eveningIntelligence($previewPreviousDay);
        $renderedTwoDaysAgo = $formatter->eveningIntelligence($builder->build('2026-09-21'));
        $rendered = implode("\n", [
            $renderedToday,
            $renderedPreviousDay,
            $renderedTwoDaysAgo,
        ]);

        expect($preview['sections'])->not->toBeEmpty()
            ->and($preview['mode']['read_only'])->toBeTrue()
            ->and($preview['mode']['mutations'])->toBe(0)
            ->and($preview['mode']['telegram_actions'])->toBe(0)
            ->and($recurrencesBeforeFormatting)->not->toBeEmpty()
            ->and($preview['recurrences'])->toBe($recurrencesBeforeFormatting)
            ->and($renderedRawSafeOnly)->toContain('Жалюзи упала, не могу повесить')
            ->not->toContain('Значимых операционных событий не зафиксировано.')
            ->and($renderedComposedOnly)->toContain('У вытяжки не работает свет.')
            ->not->toContain('Значимых операционных событий не зафиксировано.')
            ->and($renderedToday)->toContain('Жалюзи упала, не могу повесить')
            ->toContain('У вытяжки не работает свет.')
            ->not->toContain('Значимых операционных событий не зафиксировано.')
            ->and($renderedPreviousDay)->not->toContain('Простынь большая, жёлтое пятно')
            ->toContain('На кухне не работает вытяжка.')
            ->not->toContain('Значимых операционных событий не зафиксировано.')
            ->and($rendered)
            ->not->toContain('Не могу тут к вай фаю подключиться')
            ->not->toContain('Не работает.')
            ->not->toContain('Он давно не работает.')
            ->not->toContain('Сфоткать не могу гости на диване.')
            ->not->toContain('Не могу дозвониться.')
            ->not->toContain('Значимых операционных событий не зафиксировано.')
            ->toContain('У вытяжки не работает свет.')
            ->toContain('Жалюзи упала, не могу повесить')
            ->toContain('Обнаружено бракованное полотенце.')
            ->toContain('правильный код — 1291')
            ->toContain('Курьер забрал не всё грязное бельё.')
            ->not->toContain('Простынь большая, жёлтое пятно')
            ->toContain('Сломана вешалка.')
            ->toContain('Не работает свет.')
            ->toContain('На кухне не работает вытяжка.')
            ->toContain('Проблема с доступом')
            ->toContain('Обнаружена грязная посуда.')
            ->not->toContain('Уточняли наличие запасной бумаги.')
            ->not->toContain('🔄 Требует внимания:', 'Проверить свет у вытяжки.')
            ->not->toContain('⚠️ Повторяется:');
    } finally {
        Carbon::setTestNow();
    }
});

it('builds a district shift handoff from explicit editorial states', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-25 22:00:00', 'Europe/Rome'));

    try {
        $observer = app(TelegramOperationalEventObserver::class);
        $record = function (string $summary, string $sentAt, string $messageId, string $threadId, array $types, int $apartmentId) use ($observer): array {
            $message = TelegramOperationalTestDatabase::message(
                'Не работает свет в комнате 1.',
                sentAt: $sentAt,
                messageId: $messageId,
                threadId: $threadId,
            );
            $result = $observer->observe($message);
            $event = TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->firstOrFail();
            $message->update(['text' => $summary]);
            $event->update([
                'primary_type' => $types[0],
                'types' => $types,
                'summary' => $summary,
                'status' => 'open',
                'apartment_id' => $apartmentId,
            ]);
            $event->evidence()->update([
                'role' => 'report',
                'transition' => 'created',
                'status_before' => null,
                'status_after' => 'open',
            ]);
            DB::table('apartments')->updateOrInsert(['id' => $apartmentId], ['name' => 'Via Editorial '.$apartmentId]);

            return $event->fresh()->toArray();
        };

        $record('Я задержусь на 10 минут.', '2026-09-23 08:00:00', '2401', '2401', ['delay'], 101);
        $record('Простынь большая, жёлтое пятно; заменила, брак.', '2026-09-23 08:05:00', '2402', '2402', ['quality_issue'], 102);
        $record('Кровать. Брак наволочки. Заменила.', '2026-09-23 08:07:00', '2409', '2409', ['quality_issue'], 109);
        $record('Стою здесь, консьержа нет. Не открывают пока.', '2026-09-22 08:10:00', '2403', '2403', ['problem'], 103);
        $record('В программе гостевой локер: ошибка, правильный код — 1291.', '2026-09-22 08:15:00', '2404', '2404', ['problem'], 104);
        $record('Переключатель не работает, должен прийти мастер.', '2026-09-23 08:20:00', '2405', '2405', ['problem'], 105);
        $record('Обнаружена грязная посуда.', '2026-09-21 08:25:00', '2406', '2406', ['quality_issue'], 106);
        $record('Думаю не проблема будет.', '2026-09-23 08:30:00', '2407', '2407', ['problem'], 107);
        $record('Туалетную бумагу не могу найти.', '2026-09-23 08:35:00', '2408', '2408', ['problem'], 108);

        $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-23', [
            'district' => ['label' => 'Navigli'],
        ]);
        $rendered = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);
        $renderedNextDay = app(TelegramDigestFormatter::class)->eveningIntelligence(
            app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-24', ['district' => ['label' => 'Navigli']]),
        );
        $editorials = collect($preview['events'])->keyBy('summary')->map(fn (array $item) => $item['editorial']);
        $carryOverItems = collect($preview['editorial_sections'])->firstWhere('key', 'carry_over')['items'] ?? [];
        $carryOverContextLabels = collect($carryOverItems)->pluck('context_label');
        $paperEventKey = collect($preview['events'])->firstWhere('summary', 'Туалетную бумагу не могу найти.')['event_key'];
        $attentionAndActionEventKeys = collect($preview['editorial_sections'])
            ->whereIn('key', ['attention', 'actions'])
            ->flatMap(fn (array $section) => $section['items'])
            ->pluck('event_key');

        expect($editorials['Простынь большая, жёлтое пятно; заменила, брак.'])
            ->toMatchArray(['relevant_today' => true, 'state' => 'completed', 'needs_attention' => false, 'next_action' => null])
            ->and($editorials['Кровать. Брак наволочки. Заменила.'])
            ->toMatchArray(['relevant_today' => true, 'state' => 'completed', 'needs_attention' => false, 'next_action' => null])
            ->and($editorials['Думаю не проблема будет.']['state'])->toBe('omit')
            ->and($editorials['Обнаружена грязная посуда.'])
            ->toMatchArray(['state' => 'omit', 'needs_attention' => false, 'next_action' => null])
            ->and(collect($preview['events'])->firstWhere('summary', 'Обнаружена грязная посуда.')['carry_over'])->toBeFalse()
            ->and($editorials['Стою здесь, консьержа нет. Не открывают пока.'])
            ->toMatchArray(['state' => 'carry_over', 'needs_attention' => false, 'next_action' => null])
            ->and($editorials['В программе гостевой локер: ошибка, правильный код — 1291.'])
            ->toMatchArray(['state' => 'carry_over', 'needs_attention' => false, 'next_action' => null])
            ->and($editorials['Туалетную бумагу не могу найти.'])
            ->toMatchArray(['state' => 'informational', 'needs_attention' => false, 'next_action' => null])
            ->and($editorials['Переключатель не работает, должен прийти мастер.'])
            ->toMatchArray([
                'relevant_today' => true,
                'state' => 'active',
                'needs_attention' => true,
                'next_action' => 'Вызвать мастера для ремонта переключателя.',
            ])
            ->and(collect($preview['events'])->firstWhere('summary', 'Переключатель не работает, должен прийти мастер.')['carry_over'])->toBeFalse()
            ->and($rendered)
            ->toContain('🌙 Navigli — проблемы за день · 23.09.2026')
            ->toContain('Via Editorial 105 — Не работает переключатель, требуется мастер.')
            ->not->toContain('Via Editorial 102', 'Via Editorial 109', 'Via Editorial 103', 'Via Editorial 104', 'Via Editorial 108')
            ->not->toContain('Осталось сделать:', '🔄 Требует внимания:', '⚠️ Осталось с прошлых дней:', 'грязная посуда', 'Думаю не проблема будет')
            ->and(collect($preview['daily_problems'])->pluck('context_label'))->toContain('Via Editorial 105')
            ->and($renderedNextDay)->toBe("🌙 Navigli — проблемы за день · 24.09.2026\n\n✅ Незакрытых проблем за день не зафиксировано.")
            ->and($attentionAndActionEventKeys)->not->toContain($paperEventKey);
    } finally {
        Carbon::setTestNow();
    }
});


it('selects only unresolved daily problem cards while retaining excluded events for audit', function (string $source, string $date, string $status, bool $included) {
    $message = TelegramOperationalTestDatabase::message('Не работает свет.', sentAt: $date.' 10:42:00', messageId: '4101');
    $result = app(TelegramOperationalEventObserver::class)->observe($message);
    $message->update(['text' => $source]);
    $event = TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->firstOrFail();
    $event->update(['summary' => $source, 'primary_type' => 'problem', 'types' => ['problem'], 'status' => $status]);
    $event->evidence()->update(['status_after' => $status]);
    $before = $event->fresh()->toArray();

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-30');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($preview['daily_problems'])->toHaveCount($included ? 1 : 0)
        ->and($preview['events_included'])->toBe($included ? 1 : 0)
        ->and($preview['current_events_included'])->toBe($included ? 1 : 0)
        ->and($preview['carry_over_events_included'])->toBe(0)
        ->and($preview['no_material_events'])->toBe(! $included)
        ->and($event->fresh()->toArray())->toBe($before);
    if (! $included) {
        expect($text)->toContain('✅ Незакрытых проблем за день не зафиксировано.')->not->toContain('•');
    }
})->with([
    'current defect' => ['Не работает замок.', '2026-09-30', 'open', true],
    'master already called but defect remains' => ['Переключатель не работает, мастера вызвали.', '2026-09-30', 'open', true],
    'completed replacement despite stale open ledger' => ['Простынь большая, жёлтое пятно; заменила, брак.', '2026-09-30', 'open', false],
    'blanket replaced' => ['Плед грязный, взяла новый такой же.', '2026-09-30', 'open', false],
    'blanket not replaced' => ['Плед грязный, не взяла новый.', '2026-09-30', 'open', true],
    'mold without replacement' => ['Плесень на наволочке, замены нет.', '2026-09-30', 'open', true],
    'informational question' => ['Во сколько здесь заезд?', '2026-09-30', 'open', false],
    'routine completed housekeeping' => ['Уборку закрыли.', '2026-09-30', 'open', false],
    'resolved ledger' => ['Не работает замок.', '2026-09-30', 'resolved', false],
    'historical unresolved defect' => ['Не работает замок.', '2026-09-29', 'open', false],
]);

it('attributes a daily card to the original report without inventing access details', function (bool $withMetadata) {
    $message = TelegramOperationalTestDatabase::message('Не работает замок', sentAt: '2026-09-30 10:42:00', messageId: '4201', chatId: $withMetadata ? '-10012345' : '12345');
    $message->telegramUser->update(['full_name' => $withMetadata ? 'Test Worker' : null]);
    $result = app(TelegramOperationalEventObserver::class)->observe($message);
    $event = TelegramOperationalEvent::query()->where('event_key', $result['event_key'])->firstOrFail();
    $later = TelegramOperationalTestDatabase::message('Не работает замок, неисправность замка подтвердили.', sentAt: '2026-09-30 11:00:00', messageId: '4202', chatId: $withMetadata ? '-10012345' : '12345', userId: '202');
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $later->id, 'source_revision_hash' => str_repeat('d', 64),
        'evaluation_kind' => 'message', 'state' => 'completed', 'outcome' => 'evidence',
        'reason_code' => 'operational_problem', 'confidence' => 'high', 'is_current_revision' => true,
        'processed_at' => '2026-09-30 11:00:00',
    ]);
    $event->evidence()->create([
        'observation_id' => $observation->id, 'role' => 'report', 'transition' => 'evidence',
        'status_before' => 'open', 'status_after' => 'open', 'confidence' => 'high',
        'occurred_at' => Carbon::parse('2026-09-30 11:00:00', 'Europe/Rome'), 'is_current_revision' => true,
    ]);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-30');
    $card = collect($preview['daily_problems'])->sole();
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($card['quote'])->toBe('Не работает замок')
        ->and($card['author_name'])->toBe($withMetadata ? 'Test Worker' : null)
        ->and($card['source_url'])->toBe($withMetadata ? 'https://t.me/c/12345/4201' : null)
        ->and($text)->toContain('10:42', '💬 «Не работает замок»', 'Незакрытых проблем: 1')
        ->not->toContain('входной', 'электронный', 'не может попасть', 'дверь закрыта', 'Осталось сделать:')
        ->and(substr_count($text, '• '))->toBe(1);
    if ($withMetadata) {
        expect($text)->toContain('👤 Test Worker · 10:42', 'https://t.me/c/12345/4201');
    } else {
        expect($text)->not->toContain('👤', '🔗', 'Worker 202');
    }
})->with([true, false]);

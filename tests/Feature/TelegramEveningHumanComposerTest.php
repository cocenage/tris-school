<?php

use App\Models\TelegramMessage;
use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalObservation;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningHumanComposer;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(fn () => TelegramOperationalTestDatabase::refresh());
afterEach(fn () => TelegramOperationalTestDatabase::purge());

it('uses several evidence messages to explain one access situation', function () {
    $first = TelegramOperationalTestDatabase::message('Стою здесь, консьержа нет.', messageId: '101');
    $second = TelegramOperationalTestDatabase::message('Не открывают пока. Если сможешь открыть удалённо.', messageId: '102');

    $human = app(TelegramEveningHumanComposer::class)->compose(humanItem(
        'Не открывают пока.',
        [$first->id, $second->id],
        ['problem'],
    ));

    expect($human)->toMatchArray([
        'include' => true,
        'follow_up' => 'Проверить доступ в квартиру.',
    ])->and(mb_strtolower($human['summary']))->toContain('консьержа нет', 'не открывают')
        ->not->toContain('замок', 'сломана', 'потому что');
});

it('returns explicit omit, composed, raw-safe, and technical-failure outcomes', function () {
    $composer = app(TelegramEveningHumanComposer::class);
    $fixtures = [
        ['Не работает.', ['problem'], 'omit'],
        ['Он давно не работает.', ['problem'], 'omit'],
        ['Не могу дозвониться.', ['problem'], 'omit'],
        ['Сфоткать не могу гости на диване.', ['problem'], 'omit'],
        ['Не могу тут к вай фаю подключиться, поэтому так отправляется 🥲', ['problem'], 'omit'],
        ['Жалюзи упала не могу повесить так как очень высоко.', ['problem'], 'composed'],
        ['Обнаружен брак маленького полотенца, замены нет.', ['quality_issue'], 'composed'],
        ['На кухне вытяжка не работает.', ['problem'], 'composed'],
    ];

    foreach ($fixtures as $index => [$text, $types, $decision]) {
        $message = TelegramOperationalTestDatabase::message($text, messageId: (string) (2500 + $index));
        $result = $composer->compose(humanItem($text, [$message->id], $types));

        expect($result['decision'])->toBe($decision);
        if (str_contains($text, 'Жалюзи')) {
            expect($result['summary'])->toContain('Упали жалюзи', 'установить обратно не удалось', 'высоты')
                ->not->toContain('сломаны', 'мастер');
        }
    }

    expect($composer->compose(humanItem('Не работает свет.', [], ['problem']))['decision'])
        ->toBe('omit');
});

it('does not let missing evidence trigger the formatter raw-summary fallback', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Lecco 93 Green'],
        'sections' => [['key' => 'attention', 'items' => [[
            ...humanItem('Он может поэтому и не работает, потому что уже включен режим был.', [], ['problem']),
            'carry_over' => true,
            'open_age_days' => 8,
        ]]]],
    ]);

    expect($text)
        ->not->toContain('Он может поэтому и не работает')
        ->not->toContain('Открыто 8 дней.');
});

it('allows concrete object-and-fact summaries while omitting objectless or context chatter', function (string $text, string $decision, array $facts) {
    $message = TelegramOperationalTestDatabase::message($text, messageId: (string) fake()->unique()->numberBetween(3000, 9999));

    $human = app(TelegramEveningHumanComposer::class)->compose(humanItem($text, [$message->id], ['problem']));
    expect($human['decision'])->toBe($decision);
    if ($facts !== []) {
        expect(mb_strtolower($human['summary']))->toContain(...$facts);
    } else {
        expect($human)->toMatchArray(['include' => false, 'summary' => null, 'follow_up' => null]);
    }
    if (str_contains($text, 'заменила')) {
        expect($human)->toMatchArray(['completed' => true, 'follow_up' => null]);
    }
})->with([
    ['Простынь большая, жёлтое пятно; заменила, брак.', 'composed', ['простыня', 'пятном', 'заменена']],
    ['Сломана вешалка.', 'raw_safe', ['сломана', 'вешалка']],
    ['В ванной треснула плитка.', 'raw_safe', ['ванной', 'треснула', 'плитка']],
    ['Не работает.', 'omit', []],
    ['Он давно не работает.', 'omit', []],
    ['Сфоткать не могу гости на диване.', 'omit', []],
    ['Не могу тут к вай фаю подключиться, поэтому так отправляется.', 'omit', []],
]);

it('turns courier evidence into a concise linen handoff', function () {
    $message = TelegramOperationalTestDatabase::message('Курьер бельё принёс, но грязное не забрал.', messageId: '201');
    $human = app(TelegramEveningHumanComposer::class)->compose(humanItem(
        'Курьер бельё принёс, но грязное не забрал.',
        [$message->id],
        ['problem'],
    ));

    expect($human['summary'])->toBe('Курьер привёз чистое бельё, но не забрал грязное.')
        ->and($human['follow_up'])->toBe('Уточнить, когда курьер заберёт грязное бельё.');
});

it('suppresses unknown fragments instead of inventing their object', function (string $fragment) {
    $message = TelegramOperationalTestDatabase::message($fragment, messageId: (string) fake()->unique()->numberBetween(300, 999));

    expect(app(TelegramEveningHumanComposer::class)->compose(humanItem(
        $fragment,
        [$message->id],
        ['problem'],
    )))->toMatchArray(['include' => false, 'summary' => null, 'follow_up' => null]);
})->with(['Сломана.', 'Это ошибка(.', 'Есть грязные моменты.']);

it('cleans confirmed production phrases without inventing an issue from instructions or fragments', function () {
    $cases = [
        ['Коврик брак.', ['quality_issue'], true, 'Обнаружен брак коврика.'],
        ['Очень жаль что нет посудомойки((( А то вся посуда грязная от маленьких ложок до кастрюль.', ['quality_issue'], true, 'Вся посуда была грязной.'],
        ['И фото загрязнений пожалуйста.', ['quality_issue'], false, null],
        ['Оформи пожалуйста запрос на грязную квартиру.', ['quality_issue'], false, null],
        ['Если есть грязное постельное, нужно фото прикрепить.', ['quality_issue'], false, null],
        ['Отмечен риск: на более быстрый.', ['risk'], false, null],
    ];

    foreach ($cases as $index => [$text, $types, $include, $summary]) {
        $message = TelegramOperationalTestDatabase::message($text, messageId: (string) (1600 + $index));
        $result = app(TelegramEveningHumanComposer::class)->compose(humanItem($text, [$message->id], $types));

        expect($result['include'])->toBe($include)
            ->and($result['summary'])->toBe($summary);
    }
});

it('recovers a linen defect object from related evidence', function () {
    $fragment = TelegramOperationalTestDatabase::message('Сломана.', messageId: '401');
    $context = TelegramOperationalTestDatabase::message('Брак полотенца и пододеяльника.', messageId: '402');
    $human = app(TelegramEveningHumanComposer::class)->compose(humanItem(
        'Сломана.',
        [$fragment->id, $context->id],
        ['quality_issue'],
    ));

    expect($human['include'])->toBeTrue()
        ->and($human['summary'])->toContain('брак', 'полотенца', 'пододеяльника')
        ->not->toContain('наволочки', 'заменено');
});

it('does not add a clean textile mention to the coordinated defect list', function () {
    $message = TelegramOperationalTestDatabase::message('Брак полотенца. Пододеяльник чистый.', messageId: '403');
    $human = app(TelegramEveningHumanComposer::class)->compose(humanItem($message->text, [$message->id], ['quality_issue']));

    expect($human['summary'])->toContain('бракованное полотенце')
        ->not->toContain('брак пододеяльника', 'бракованное постельное бельё');
});

it('keeps concrete question follow-up and drops contextless chat questions', function () {
    $linen = TelegramOperationalTestDatabase::message('Для дивана постельное есть в шкафу?', messageId: '501');
    $noise = TelegramOperationalTestDatabase::message('Да, это гости или ты?☺️ И сколько осталось.', messageId: '502');
    $composer = app(TelegramEveningHumanComposer::class);

    expect($composer->compose(humanItem($linen->text, [$linen->id], ['request'])))->toMatchArray([
        'include' => true,
        'summary' => 'Уточняли наличие постельного белья для дивана.',
        'follow_up' => 'Уточнить наличие постельного белья для дивана.',
    ])->and($composer->compose(humanItem($noise->text, [$noise->id], ['request'])))
        ->toMatchArray(['include' => false]);
});

it('suppresses acknowledgement-only chatter without losing an operational fact after it', function () {
    $acknowledgement = TelegramOperationalTestDatabase::message('Да, хорошо, спасибо.', messageId: '503');
    $fact = TelegramOperationalTestDatabase::message('Да, курьер забрал грязное бельё.', messageId: '504');
    $composer = app(TelegramEveningHumanComposer::class);

    expect($composer->compose(humanItem($acknowledgement->text, [$acknowledgement->id], ['request'])))
        ->toMatchArray(['include' => false])
        ->and($composer->compose(humanItem($fact->text, [$fact->id], ['action'])))
        ->toMatchArray([
            'include' => true,
            'summary' => 'Курьер забрал грязное бельё.',
            'completed' => true,
            'follow_up' => null,
        ]);
});

it('does not let the formatter infer or render ledger sections without Builder decisions', function () {
    $preview = [
        'date' => '2026-09-20',
        'timezone' => 'Europe/Rome',
        'district' => ['label' => 'Lodi'],
        'sections' => [[
            'key' => 'attention',
            'items' => [[
                ...humanItem('Не работает свет.', [999999], ['problem']),
                'context_label' => 'Via X',
            ]],
        ]],
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($text)->not->toContain('Не работает свет.');
});

it('uses only a structurally confirmed actor and location in the human delay', function () {
    $message = TelegramOperationalTestDatabase::message('Я задержусь на 10 минут.', messageId: '601');
    $item = [
        ...humanItem('Сотрудник сообщил о задержке.', [$message->id], ['delay']),
        'actor_user_id' => 10,
        'actor_name' => 'Анна',
        'apartment_id' => 20,
        'context_label' => 'Via Confirmed',
    ];

    $human = app(TelegramEveningHumanComposer::class)->compose($item);

    expect($human['summary'])->toBe('Анна задерживается примерно на 10 минут.');
});

it('keeps ordinary lateness out of daily problems unless the source proves operational impact', function () {
    $composer = app(TelegramEveningHumanComposer::class);
    $ordinary = [
        'include' => true,
        'summary' => 'Анна задерживается примерно на 10 минут.',
        'context_label' => 'Via Confirmed',
        'types' => ['delay'],
        '_citation_candidates' => [['text' => 'Я немного опоздаю минут на 10.', 'role' => 'report']],
    ];
    $impact = $ordinary;
    $impact['_citation_candidates'] = [['text' => 'Я опоздаю на 10 минут, уборка не успеет к заезду.', 'role' => 'report']];

    expect($composer->isDailyProblem($ordinary, $ordinary))->toBeTrue()
        ->and($composer->isDailyProblem($impact, $impact))->toBeTrue();

    $formatter = app(TelegramDigestFormatter::class);
    $render = fn (array $problem): string => $formatter->eveningIntelligence([
        'district' => ['label' => 'Como'],
        'date' => '2026-09-20',
        'daily_problems' => [$problem],
    ]);
    $ordinaryCard = $ordinary + ['quote' => 'Я немного опоздаю минут на 10.'];
    $impactCard = $impact + ['quote' => 'Я опоздаю на 10 минут, уборка не успеет к заезду.'];

    expect($render($ordinaryCard))->toContain('Незакрытых проблем за день не зафиксировано.')
        ->and($render($impactCard))->toContain('Анна задерживается примерно на 10 минут.');
});

it('composes a linen shortage with courier dependency and decodes entities in human text', function () {
    $message = TelegramOperationalTestDatabase::message(
        'И мне кур&#039;эра ждать, у меня один комплект белья, второй брак.',
        messageId: '6101',
    );
    $result = app(TelegramEveningHumanComposer::class)->compose(humanItem($message->text, [$message->id], ['quality_issue']));
    $preview = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Como'],
        'date' => '2026-09-20',
        'daily_problems' => [[
            'event_key' => 'entity-quote', 'context_label' => 'Via San Mirocle, 4',
            'summary' => $result['summary'], 'quote' => $message->text,
        ]],
    ]);

    expect($result['summary'])->toContain('Не хватало пригодного белья', 'один комплект', 'второй оказался бракованным', 'курьера')
        ->not->toBe($message->text)
        ->and($preview)->toContain("кур'эра")->not->toContain('&#039;');
});

it('requires a meaningful location and factual source before a daily problem is rendered', function () {
    $formatter = app(TelegramDigestFormatter::class);
    $item = [
        'include' => true,
        'summary' => 'Не работает свет.',
        'context_label' => '19',
        'types' => ['problem'],
        '_citation_candidates' => [['text' => 'Не работает свет.', 'role' => 'report']],
    ];
    expect($formatter->eveningIntelligence([
        'district' => ['label' => 'Como'], 'date' => '2026-09-20', 'daily_problems' => [$item],
    ]))->toContain('Незакрытых проблем за день не зафиксировано.');
    $item['context_label'] = null;
    $item['quote'] = 'Не работает свет.';
    expect($formatter->eveningIntelligence([
        'district' => ['label' => 'Como'], 'date' => '2026-09-20', 'daily_problems' => [$item],
    ]))->toContain('Незакрытых проблем за день не зафиксировано.');
    $item['context_label'] = 'Via Resolved';
    $item['quote'] = null;
    expect($formatter->eveningIntelligence([
        'district' => ['label' => 'Como'], 'date' => '2026-09-20', 'daily_problems' => [$item],
    ]))->toContain('Незакрытых проблем за день не зафиксировано.');
});

it('does not attribute an ordinary apartment question to its message author', function () {
    $message = TelegramOperationalTestDatabase::message('Во сколько здесь заезд?', messageId: '602');
    $item = [
        ...humanItem($message->text, [$message->id], ['unanswered_question']),
        'actor_user_id' => null,
        'actor_name' => null,
        'context_label' => 'Via Question',
    ];

    $human = app(TelegramEveningHumanComposer::class)->compose($item);

    expect($human['summary'])->toBe('Уточняли время заезда.')
        ->and($item['actor_user_id'])->toBeNull()
        ->and($item['actor_name'])->toBeNull();
});

it('restores a broken handle object from bounded evidence without mutating source data', function () {
    $context = TelegramOperationalTestDatabase::message('Ручка окна опять отвалилась.', messageId: '603');
    $fragment = TelegramOperationalTestDatabase::message('Сломана.', messageId: '604');
    $item = humanItem('Сломана.', [$context->id, $fragment->id], ['problem']);
    $before = [$context->fresh()->toArray(), $fragment->fresh()->toArray()];

    $result = app(TelegramEveningHumanComposer::class)->compose($item);

    expect($result)->toMatchArray([
        'include' => true,
        'summary' => 'Отвалилась ручка окна.',
        'follow_up' => 'Проверить крепление ручки окна.',
    ])->and([$context->fresh()->toArray(), $fragment->fresh()->toArray()])->toBe($before);
});

it('restores the window subject for a leading contrast fragment only when linked evidence names it', function () {
    $object = TelegramOperationalTestDatabase::message('Окно на кухне.', messageId: '607');
    $fragment = TelegramOperationalTestDatabase::message(
        'Но на кухне не плотно закрывается и ручка не работает.',
        messageId: '608',
    );

    $result = app(TelegramEveningHumanComposer::class)->compose(humanItem(
        $fragment->text,
        [$object->id, $fragment->id],
        ['problem'],
    ));

    expect($result['summary'])->toBe('Окно на кухне не плотно закрывается и ручка не работает.');
});

it('suppresses a dirty referent fragment unless bounded evidence establishes its object', function () {
    $fragment = TelegramOperationalTestDatabase::message('Нет..это грязное.', messageId: '605');
    $composer = app(TelegramEveningHumanComposer::class);
    $withoutObject = $composer->compose(humanItem('Нет..это грязное.', [$fragment->id], ['quality_issue']));
    $topicTitleOnly = $composer->compose(humanItem(
        'Imbonati 88 DEER постельное владельца — Нет..это грязное.',
        [$fragment->id],
        ['quality_issue'],
    ));

    $object = TelegramOperationalTestDatabase::message('Постельное владельца.', messageId: '606');
    expect($composer->compose(humanItem('Нет..это грязное.', [$fragment->id], ['quality_issue'])))
        ->toMatchArray(['include' => false, 'summary' => null]);
    $withObject = $composer->compose(humanItem(
        'Imbonati 88 DEER постельное владельца — Нет..это грязное.',
        [$object->id, $fragment->id],
        ['quality_issue'],
    ));
    $formatter = app(TelegramDigestFormatter::class);
    $withoutObjectPreview = $formatter->eveningIntelligence([
        'district' => ['label' => 'Certosa'],
        'editorial_sections' => [],
    ]);
    $withObjectPreview = $formatter->eveningIntelligence([
        'district' => ['label' => 'Certosa'],
        'daily_problems' => [[
            'event_key' => 'dirty-linen',
            'context_label' => 'Imbonati 88 DEER',
            'summary' => $withObject['summary'],
        ]],
    ]);

    expect($withoutObject)->toMatchArray(['include' => false, 'handled' => true])
        ->and($topicTitleOnly)->toMatchArray(['include' => false, 'handled' => true])
        ->and($withObject)->toMatchArray([
            'include' => true,
            'summary' => 'Постельное бельё владельца оказалось грязным.',
            'follow_up' => null,
        ])
        ->and($withoutObjectPreview)->not->toContain('Нет..это грязное.', 'Постельное владельца')
        ->and($withObjectPreview)->not->toContain('Imbonati 88 DEER', 'Нет..это грязное.');
});

it('humanizes production-shaped operational facts and suppresses contextless fragments', function () {
    $cases = [
        ['Если что, в гостевом локере на улице у нас в программе ошибка должен быть 1291', ['problem']],
        ['Гость забыл конверт, как найдешь, сообщи о находке.', ['request']],
        ['Гость говорит, что включил посудомоечную машину, но из неё стала вытекать вода.', ['problem']],
        ['Я возьму с нового комплекта и сделаю его грязным.', ['action']],
        ['Они вообще не открываются почему-то.', ['problem']],
    ];
    $items = [];

    foreach ($cases as $index => [$text, $types]) {
        $message = TelegramOperationalTestDatabase::message($text, messageId: (string) (620 + $index));
        $items[] = humanItem($text, [$message->id], $types);
    }

    $results = collect($items)->map(fn (array $item): array => app(TelegramEveningHumanComposer::class)->compose($item));
    $text = $results->pluck('summary')->filter()->implode("\n");
    $actions = $results->pluck('follow_up')->filter()->implode("\n");

    expect($text)
        ->toContain('В программе указан неверный код гостевого локера; правильный код — 1291.')
        ->toContain('Гость забыл конверт; нужно найти его и сообщить о находке.')
        ->toContain('Из посудомоечной машины вытекала вода; нужно проверить её состояние.')
        ->and($actions)
        ->toContain('Исправить код гостевого локера в программе.')
        ->toContain('Найти конверт и сообщить о находке.')
        ->toContain('Проверить состояние посудомоечной машины.')
        ->not->toContain('Я возьму с нового комплекта')
        ->not->toContain('Они вообще не открываются');
});

it('consolidates one apartment courier situation only in the human digest', function () {
    $messages = collect([
        'После курьера осталось грязное бельё.',
        'Курьер забрал не всё грязное бельё.',
        'Нужно фото грязного белья.',
    ])->map(fn (string $text, int $index) => TelegramOperationalTestDatabase::message(
        $text,
        sentAt: '2026-09-20 10:0'.$index.':00',
        messageId: (string) (650 + $index),
    ));
    $items = $messages->map(fn ($message): array => [
        ...humanItem($message->text, [$message->id], ['problem']),
        'context_label' => 'Baiamonti 2',
    ])->all();

    $text = app(TelegramEveningHumanComposer::class)->compose($items[1])['summary'];

    expect($items)->toHaveCount(3)
        ->and($text)->toBe('Курьер забрал не всё грязное бельё.')
        ->and(substr_count($text, 'Курьер забрал не всё грязное бельё.'))->toBe(1)
        ->and($items[0]['summary'])->toContain('После курьера осталось')
        ->and($items[2]['summary'])->toContain('Нужно фото грязного белья');
});

it('consolidates closely related same-topic linen and kitchen cleanliness evidence only in the human digest', function () {
    $cases = [
        ['2026-09-20 10:00:00', '701', 'Возьму с брака простынь и т.д', ['quality_issue'], 'Via Giuseppe Compagnoni 12'],
        ['2026-09-20 10:18:00', '702', 'Еще брак наволочки дырка и мал полотенце', ['quality_issue'], 'Via Giuseppe Compagnoni 12'],
        ['2026-09-20 11:00:00', '703', 'Гости оставили отзыв: на кухне очень грязно.', ['quality_issue'], 'Via M. Malpighi, 3'],
        ['2026-09-20 11:16:00', '704', 'Посуда грязная, пришлось еще убирать.', ['quality_issue'], 'Via M. Malpighi, 3'],
        ['2026-09-20 11:20:00', '705', 'Не работает чайник.', ['problem'], 'Via M. Malpighi, 3'],
    ];
    foreach ($cases as [$sentAt, $messageId, $text, $types, $topicTitle]) {
        $message = TelegramOperationalTestDatabase::message($text, sentAt: $sentAt, messageId: $messageId, threadId: $topicTitle === 'Via Giuseppe Compagnoni 12' ? '701' : '702');
        $message->topic->update(['title' => $topicTitle]);
        humanScenarioEvent($message, $types);
    }

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-20');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect(substr_count($text, 'Via Giuseppe Compagnoni 12 —'))->toBe(1)
        ->and($text)->toContain('Обнаружен брак постельного белья: простыня, наволочка и маленькое полотенце.')
        ->and(substr_count($text, 'Via M. Malpighi, 3 —'))->toBe(2)
        ->and($text)->toContain('Квартира была сильно загрязнена: кухня и посуда требовали дополнительной уборки.')
        ->toContain('Не работает чайник.');
});

it('recognizes a factual guest quality report without promoting instructions or questions', function (string $text, bool $expected) {
    expect(app(TelegramEveningHumanComposer::class)->isProblemEvidence(
        ['text' => $text, 'role' => 'report'],
        ['quality_issue'],
    ))->toBe($expected);
})->with([
    'guest report' => ['Гости оставили отзыв: на кухне грязно и много пыли.', true],
    'conditional guidance' => ['Если гости оставят отзыв, что на кухне грязно, пришли фото.', false],
    'photo instruction' => ['Пришли фото: гости сообщили о грязи на кухне.', false],
    'question' => ['Гости спрашивают, на кухне грязно?', false],
    'report before conditional follow-up' => ['Стою здесь, консьержа нет. Не открывают пока. Если сможешь открыть удалённо.', true],
    'only a conditional defect' => ['Сегодня проверяем квартиру. Если на кухне грязно, гости оставят отзыв.', false],
]);

it('renders the supplied September 20 five-district scenarios as unresolved daily problem cards', function () {
    Http::fake();
    Queue::fake();
    $fixtures = [
        'Navigli' => [
            ['Стою здесь, консьержа нет. Не открывают пока. Если сможешь открыть удалённо.', 'Via N1', ['problem']],
            ['Курьер бельё принёс, но грязное не забрал.', 'Via N2', ['problem']],
            ['Я немного задерживаюсь.', 'Via N3', ['delay'], 'Анна'],
            ['Буду через 10 минут.', 'Via N3', ['delay'], 'Анна'],
            ['Мне же не нужно ПМ ждать?', 'Via N4', ['request']],
            ['Не знаю, было ли это сломано раньше.', 'Via N5', ['problem']],
        ],
        'Lodi' => [
            ['Не работает свет.', 'Via L1', ['problem']],
            ['Да, это гости или ты?☺️ И сколько осталось.', 'Via L2', ['request']],
            ['Хорошо, поняла, спасибо.', 'Via L3', ['request']],
            ['Я немного задерживаюсь.', 'Via L4', ['delay'], 'Мария'],
            ['Одеяла возьми из шкафа и разложи по кроватям.', 'Via L5', ['action']],
        ],
        'Como' => [
            ['Гости оставили отзыв: на кухне грязно и много пыли.', 'Via C1', ['quality_issue']],
        ],
        'Certosa' => [
            ['Стою у двери, не открывают.', 'Via T1', ['problem']],
            ['Курьер забрал не всё бельё.', 'Via T2', ['problem']],
            ['Немного задерживаюсь.', 'Via T3', ['delay'], 'Ольга'],
            ['Что это за звук?', 'Via T4', ['request']],
            ['Скачай видео и закрой уборку.', 'Via T5', ['action']],
        ],
        'Lambrate' => [
            ['Сломана.', 'Via B1', ['problem']],
            ['Это ошибка(.', 'Via B2', ['problem']],
            ['Брак полотенца.', 'Via B3', ['quality_issue']],
            ['Брак пододеяльника.', 'Via B4', ['quality_issue']],
            ['Одеяла здесь есть?', 'Via B5', ['unanswered_question']],
            ['Да, хорошо, спасибо.', 'Via B6', ['request']],
        ],
    ];
    $previews = [];
    $messageId = 700;
    $districtIndex = 0;

    foreach ($fixtures as $district => $events) {
        $chatId = '-100'.(2000 + ++$districtIndex);
        $route = ['key' => strtolower($district), 'label' => $district, 'chat_id' => $chatId, 'latitude' => 45, 'longitude' => 9];
        config(['services.telegram.digest_districts.'.strtolower($district) => $route]);
        $ledger = [];

        foreach ($events as $event) {
            [$text, $apartment, $types] = $event;
            $actor = $event[3] ?? null;
            $topicId = (string) (array_search($apartment, array_values(array_unique(array_column($events, 1))), true) + 1);
            $message = TelegramOperationalTestDatabase::message(
                $text,
                sentAt: '2026-09-20 10:00:00',
                messageId: (string) ++$messageId,
                chatId: $chatId,
                threadId: $topicId,
                userId: (string) $messageId,
            );
            $message->topic->update(['title' => $apartment]);
            $message->telegramUser->update(['full_name' => $actor]);
            // The supplied delay and ETA describe one already-correlated event.
            $ledger[$apartment] = humanScenarioEvent($message, $types, $ledger[$apartment] ?? null);
        }

        expect(TelegramOperationalEvent::query()->where('telegram_chat_id', $message->telegram_chat_id)->count())->toBe(count($ledger));
        $before = TelegramOperationalEvent::query()->orderBy('id')->get()->toArray();
        $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-20', ['district' => $route]);
        expect($preview['events_considered'])->toBe(count($ledger))
            ->and($preview['daily_problems'])->not->toBeEmpty()
            ->and(TelegramOperationalEvent::query()->orderBy('id')->get()->toArray())->toBe($before);
        foreach ($preview['daily_problems'] as $card) {
            $projection = collect($preview['events'])->firstWhere('event_key', $card['event_key']);
            expect($projection['status'])->toBe('open');
            if (! in_array('delay', $projection['types'], true)) {
                expect($card['quote'])->not->toBeNull()
                    ->and($card['source_url'])->not->toBeNull();
            }
        }
        $previews[$district] = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);
        expect($previews[$district])->toContain('🌙 '.$district.' — проблемы за день · 20.09.2026')
            ->not->toContain('Решено сегодня:', 'Осталось сделать:', 'Осталось с прошлых дней:');
        $nextDay = app(TelegramEveningIntelligenceBuilder::class)->build('2026-09-21', ['district' => $route]);
        expect($nextDay['daily_problems'])->toBe([])
            ->and($nextDay['no_material_events'])->toBeTrue();
    }

    expect($previews['Navigli'])
        ->toContain('Via N1 — Стою здесь, консьержа нет. Не открывают пока.')
        ->toContain('Via N2 — Курьер привёз чистое бельё, но не забрал грязное.')
        ->not->toContain('Via N3 —')
        ->and($previews['Navigli'])->not->toContain('ПМ ждать')
        ->not->toContain('сломано раньше')
        ->and($previews['Lodi'])->toContain('Via L1 — Не работает свет.')
        ->not->toContain('Via L4 —')
        ->not->toContain('это гости или ты')
        ->not->toContain('поняла, спасибо')
        ->not->toContain('Одеяла возьми')
        ->and($previews['Como'])->toContain('Via C1 — Гости сообщили о грязи на кухне и пыли.')
        ->and($previews['Certosa'])->toContain('Via T1 — Стою у двери, не открывают.')
        ->toContain('Via T2 — Курьер забрал не всё бельё.')
        ->not->toContain('Via T3 —')
        ->not->toContain('Что это за звук')
        ->not->toContain('Скачай видео')
        ->and($previews['Lambrate'])->toContain('Via B3 — Обнаружено бракованное полотенце.')
        ->toContain('Via B4 — Обнаружен брак пододеяльника.')
        ->not->toContain('Via B5', 'Уточняли наличие одеял в квартире.', 'Уточнить наличие одеял в квартире.')
        ->not->toContain('Сломана')
        ->not->toContain('Это ошибка')
        ->not->toContain('Да, хорошо, спасибо')
        ->and(collect($previews)->implode("\n"))->not->toContain('@')
        ->not->toContain('☺️')
        ->not->toContain('Есть открытый вопрос, требующий уточнения.');
    foreach (['Navigli' => 'Via N', 'Lodi' => 'Via L', 'Como' => 'Via C', 'Certosa' => 'Via T', 'Lambrate' => 'Via B'] as $district => $prefix) {
        foreach (array_diff_key($previews, [$district => true]) as $otherPreview) {
            expect($otherPreview)->not->toContain($prefix);
        }
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function humanItem(string $summary, array $messageIds, array $types, string $status = 'open'): array
{
    return [
        'event_key' => 'event-'.sha1($summary.implode(',', $messageIds)),
        'summary' => $summary,
        'context_label' => null,
        'actor_name' => null,
        'types' => $types,
        'status' => $status,
        'evidence' => collect($messageIds)->map(fn (int $id): array => [
            'local_message_id' => $id,
            'role' => str_contains($summary, '?') ? 'question' : 'report',
            'transition' => 'created',
            'occurred_at' => '2026-09-20T10:00:00+02:00',
        ])->all(),
    ];
}

function humanScenarioEvent(TelegramMessage $message, array $types, ?TelegramOperationalEvent $event = null): TelegramOperationalEvent
{
    $isNew = $event === null;
    $event ??= TelegramOperationalEvent::query()->create([
        'event_key' => 'human-scenario:'.$message->id,
        'root_message_id' => $message->id,
        'telegram_chat_id' => $message->telegram_chat_id,
        'telegram_topic_id' => $message->telegram_topic_id,
        'primary_type' => $types[0], 'types' => $types,
        'summary' => $message->text, 'status' => 'open', 'confidence' => 'high',
        'first_observed_at' => $message->sent_at, 'last_observed_at' => $message->sent_at,
    ]);
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $message->id,
        'source_revision_hash' => hash('sha256', $message->text),
        'evaluation_kind' => 'message', 'state' => 'completed', 'outcome' => 'created',
        'reason_code' => match ($types[0]) {
            'quality_issue' => 'quality_issue',
            'delay' => 'operational_delay',
            'problem' => 'operational_problem',
            default => 'operational_question',
        },
        'confidence' => 'high', 'is_current_revision' => true, 'processed_at' => $message->sent_at,
    ]);
    $event->evidence()->create([
        'observation_id' => $observation->id,
        'role' => str_contains($message->text, '?') ? 'question' : 'report',
        'transition' => $isNew ? 'created' : 'evidence',
        'status_after' => 'open', 'confidence' => 'high',
        'occurred_at' => $message->sent_at, 'is_current_revision' => true,
    ]);

    return $event;
}

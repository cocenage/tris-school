<?php

use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningHumanComposer;
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
        'summary' => 'Возникла проблема с доступом: консьержа не было на месте, дверь не открывали.',
        'follow_up' => 'Проверить, решён ли вопрос с доступом в квартиру.',
    ]);
});

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
        ->and($human['summary'])->toBe('Обнаружен брак полотенца и пододеяльника.');
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
        ]);
});

it('keeps apartment context and falls back deterministically when evidence is unavailable', function () {
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

    expect($text)->toContain('• Via X — Не работает свет.');
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

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => [$item]]],
    ]);

    expect($text)->toContain('• Via Confirmed — Анна задерживается примерно на 10 минут.');
});

it('does not attribute an ordinary apartment question to its message author', function () {
    $message = TelegramOperationalTestDatabase::message('Во сколько здесь заезд?', messageId: '602');
    $item = [
        ...humanItem($message->text, [$message->id], ['unanswered_question']),
        'actor_user_id' => null,
        'actor_name' => null,
        'context_label' => 'Via Question',
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => [$item]]],
    ]);

    expect($text)->toContain('• Via Question — Уточняли время заезда.')
        ->not->toContain('Worker 101');
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
    $withObject = $composer->compose(humanItem(
        'Imbonati 88 DEER постельное владельца — Нет..это грязное.',
        [$object->id, $fragment->id],
        ['quality_issue'],
    ));
    $formatter = app(TelegramDigestFormatter::class);
    $withoutObjectPreview = $formatter->eveningIntelligence([
        'district' => ['label' => 'Certosa'],
        'sections' => [['key' => 'quality', 'items' => [[
            ...humanItem('Нет..это грязное.', [$fragment->id], ['quality_issue']),
            'context_label' => 'Imbonati 88 DEER постельное владельца',
        ]]]],
    ]);
    $withObjectPreview = $formatter->eveningIntelligence([
        'district' => ['label' => 'Certosa'],
        'sections' => [['key' => 'quality', 'items' => [[
            ...humanItem('Нет..это грязное.', [$object->id, $fragment->id], ['quality_issue']),
            'context_label' => 'Imbonati 88 DEER',
        ]]]],
    ]);

    expect($withoutObject)->toMatchArray(['include' => false, 'handled' => true])
        ->and($topicTitleOnly)->toMatchArray(['include' => false, 'handled' => true])
        ->and($withObject)->toMatchArray([
            'include' => true,
            'summary' => 'Постельное бельё владельца оказалось грязным.',
            'follow_up' => null,
        ])
        ->and($withoutObjectPreview)->not->toContain('Нет..это грязное.', 'Постельное владельца')
        ->and($withObjectPreview)->toContain('Imbonati 88 DEER — Постельное бельё владельца оказалось грязным.')
        ->not->toContain('Нет..это грязное.');
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

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Lambrate'],
        'sections' => [['key' => 'attention', 'items' => $items]],
    ]);

    expect($text)
        ->toContain('В программе указан неверный код гостевого локера; правильный код — 1291.')
        ->toContain('Гость забыл конверт; нужно найти его и сообщить о находке.')
        ->toContain('Из посудомоечной машины вытекала вода; нужно проверить её состояние.')
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

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Certosa'],
        'sections' => [['key' => 'attention', 'items' => $items]],
    ]);

    expect($items)->toHaveCount(3)
        ->and($text)->toContain('• Baiamonti 2 — Курьер забрал не всё грязное бельё.')
        ->and(substr_count($text, 'Курьер забрал не всё грязное бельё.'))->toBe(1)
        ->and($text)->not->toContain('После курьера осталось')
        ->not->toContain('Нужно фото грязного белья');
});

it('renders the supplied September 20 five-district scenarios as shift handoffs', function () {
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

    foreach ($fixtures as $district => $events) {
        $items = [];

        foreach ($events as $event) {
            [$text, $apartment, $types] = $event;
            $actor = $event[3] ?? null;
            $message = TelegramOperationalTestDatabase::message(
                $text,
                sentAt: '2026-09-20 10:00:00',
                messageId: (string) ++$messageId,
                chatId: (string) (-2000 - $messageId),
            );
            $items[] = [
                ...humanItem($text, [$message->id], $types),
                'context_label' => $apartment,
                'actor_name' => $actor,
            ];
        }

        $previews[$district] = app(TelegramDigestFormatter::class)->eveningIntelligence([
            'date' => '2026-09-20',
            'timezone' => 'Europe/Rome',
            'district' => ['label' => $district],
            'sections' => [['key' => 'attention', 'items' => $items]],
        ]);
    }

    expect($previews['Navigli'])
        ->toContain('Via N1 — Возникла проблема с доступом: консьержа не было на месте, дверь не открывали.')
        ->toContain('Via N2 — Курьер привёз чистое бельё, но не забрал грязное.')
        ->toContain('Via N3 — Анна задерживается примерно на 10 минут.')
        ->and(substr_count($previews['Navigli'], 'Via N3 —'))->toBe(1)
        ->and($previews['Navigli'])->not->toContain('ПМ ждать')
        ->not->toContain('сломано раньше')
        ->and($previews['Lodi'])->toContain('Via L1 — Не работает свет.')
        ->toContain('Via L4 — Мария задерживается.')
        ->not->toContain('это гости или ты')
        ->not->toContain('поняла, спасибо')
        ->not->toContain('Одеяла возьми')
        ->and($previews['Como'])->toContain('Via C1 — Гости сообщили о грязи на кухне и пыли.')
        ->and($previews['Certosa'])->toContain('Via T1 — Возникла проблема с доступом.')
        ->toContain('Via T2 — Курьер забрал не всё бельё.')
        ->toContain('Via T3 — Ольга задерживается.')
        ->not->toContain('Что это за звук')
        ->not->toContain('Скачай видео')
        ->and($previews['Lambrate'])->toContain('Via B3 — Обнаружен брак полотенца.')
        ->toContain('Via B4 — Обнаружен брак пододеяльника.')
        ->toContain('Via B5 — Уточняли наличие одеял в квартире.')
        ->toContain('Via B5 — Уточнить наличие одеял в квартире.')
        ->not->toContain('Сломана')
        ->not->toContain('Это ошибка')
        ->not->toContain('Да, хорошо, спасибо')
        ->and(collect($previews)->implode("\n"))->not->toContain('@')
        ->not->toContain('☺️')
        ->not->toContain('Есть открытый вопрос, требующий уточнения.');
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

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
        'summary' => 'Возникла проблема с доступом: консьержа не было, дверь не открывали.',
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

it('renders the supplied September 20 five-district scenarios as shift handoffs', function () {
    $fixtures = [
        'Navigli' => [
            ['Стою здесь, консьержа нет. Не открывают пока. Если сможешь открыть удалённо.', 'Via N1', ['problem']],
            ['Курьер бельё принёс, но грязное не забрал.', 'Via N2', ['problem']],
        ],
        'Lodi' => [
            ['Не работает свет.', 'Via L1', ['problem']],
            ['Да, это гости или ты?☺️ И сколько осталось.', 'Via L2', ['request']],
        ],
        'Como' => [
            ['Гости оставили отзыв: на кухне грязно и много пыли.', 'Via C1', ['quality_issue']],
        ],
        'Certosa' => [
            ['Стою у двери, не открывают.', 'Via T1', ['problem']],
            ['Курьер забрал не всё бельё.', 'Via T2', ['problem']],
        ],
        'Lambrate' => [
            ['Сломана.', 'Via B1', ['problem']],
            ['Это ошибка(.', 'Via B2', ['problem']],
            ['Брак полотенца и пододеяльника.', 'Via B3', ['quality_issue']],
        ],
    ];
    $previews = [];
    $messageId = 700;

    foreach ($fixtures as $district => $events) {
        $items = [];

        foreach ($events as [$text, $apartment, $types]) {
            $message = TelegramOperationalTestDatabase::message(
                $text,
                sentAt: '2026-09-20 10:00:00',
                messageId: (string) ++$messageId,
                chatId: (string) (-2000 - $messageId),
            );
            $items[] = [
                ...humanItem($text, [$message->id], $types),
                'context_label' => $apartment,
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
        ->toContain('Via N1 — Возникла проблема с доступом: консьержа не было, дверь не открывали.')
        ->toContain('Via N2 — Курьер привёз чистое бельё, но не забрал грязное.')
        ->and($previews['Lodi'])->toContain('Via L1 — Не работает свет.')
        ->not->toContain('это гости или ты')
        ->and($previews['Como'])->toContain('Via C1 — Гости сообщили о грязи на кухне и пыли.')
        ->and($previews['Certosa'])->toContain('Via T1 — Возникла проблема с доступом.')
        ->toContain('Via T2 — Курьер забрал не всё бельё.')
        ->and($previews['Lambrate'])->toContain('Via B3 — Обнаружен брак полотенца и пододеяльника.')
        ->not->toContain('Сломана')
        ->not->toContain('Это ошибка')
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

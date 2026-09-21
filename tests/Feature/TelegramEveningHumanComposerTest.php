<?php

use App\Models\Apartment;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningHumanComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
    ]);
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});
afterEach(function () {
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

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

it('enriches a delay actor and apartment from its source message without changing the ledger item', function () {
    $employeeId = DB::table('users')->insertGetId([
        'name' => 'Анна',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $apartment = Apartment::create(['name' => 'Via Enriched']);
    $message = TelegramOperationalTestDatabase::message('Я задержусь на 10 минут.', messageId: '601');
    $message->telegramUser->update(['linked_user_id' => $employeeId]);
    $message->topic->update(['apartment_id' => $apartment->id]);
    $item = humanItem('Сотрудник сообщил о задержке.', [$message->id], ['delay']);

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => [$item]]],
    ]);

    expect($item['actor_name'])->toBeNull()
        ->and($item['context_label'])->toBeNull()
        ->and($text)->toContain('• Via Enriched — Анна: задержка примерно на 10 минут.');
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
        ->toContain('Via N1 — Возникла проблема с доступом: консьержа не было, дверь не открывали.')
        ->toContain('Via N2 — Курьер привёз чистое бельё, но не забрал грязное.')
        ->toContain('Via N3 — Анна: задержка примерно на 10 минут.')
        ->and(substr_count($previews['Navigli'], 'Via N3 —'))->toBe(1)
        ->and($previews['Navigli'])->not->toContain('ПМ ждать')
        ->not->toContain('сломано раньше')
        ->and($previews['Lodi'])->toContain('Via L1 — Не работает свет.')
        ->toContain('Via L4 — Мария: небольшая задержка.')
        ->not->toContain('это гости или ты')
        ->not->toContain('поняла, спасибо')
        ->not->toContain('Одеяла возьми')
        ->and($previews['Como'])->toContain('Via C1 — Гости сообщили о грязи на кухне и пыли.')
        ->and($previews['Certosa'])->toContain('Via T1 — Возникла проблема с доступом.')
        ->toContain('Via T2 — Курьер забрал не всё бельё.')
        ->toContain('Via T3 — Ольга: небольшая задержка.')
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

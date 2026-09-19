<?php

use App\Services\Telegram\TelegramDigestFormatter;

it('formats an empty morning using only the supplied contract', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'staff' => ['working' => [], 'not_working' => [], 'shift' => ['total' => 0]],
        'calendar' => ['events' => []],
        'tasks' => ['items' => []],
        'mobility' => ['items' => []],
        'risks' => [],
        'telegram' => ['messages' => 0],
        'data_quality' => [],
    ]);

    expect($text)
        ->toContain('Утренняя сводка')
        ->toContain('За день значимых событий по доступным данным не обнаружено.')
        ->not->toContain('рейтинг')
        ->not->toContain('эффективность');
});

it('formats conservative evening problem and tomorrow sections', function () {
    $text = app(TelegramDigestFormatter::class)->evening([
        'date' => '2026-08-02',
        'timezone' => 'Europe/Rome',
        'forums' => [[
            'chat_title' => 'Рабочий форум',
            'topics' => [[
                'topic_title' => 'Ключи',
                'problem_signals' => 2,
                'positive_signals' => 0,
                'possible_unanswered' => true,
                'possible_resolved' => false,
                'repeated_problem' => true,
            ]],
        ]],
        'data_quality' => [],
    ]);

    expect($text)
        ->toContain('Итоги дня')
        ->toContain('Проблемы')
        ->toContain('Без ответа')
        ->toContain('Повторяющиеся сигналы')
        ->toContain('Проверить завтра')
        ->toContain('Рабочий форум / Ключи');
});

it('renders only meaningful normalized mobility events without severity labels', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'staff' => ['working' => [], 'not_working' => [], 'shift' => ['total' => 0]],
        'calendar' => ['events' => []],
        'tasks' => ['items' => []],
        'mobility' => ['items' => [
            ['risk' => 'info', 'district' => 'M1', 'summary' => 'REGOLARE'],
            ['risk' => 'low', 'district' => 'M2', 'summary' => 'Обычный режим'],
            ['risk' => 'medium', 'district' => 'M2', 'summary' => 'Частичное ограничение'],
            ['risk' => 'high', 'district' => 'M3', 'summary' => 'Линия закрыта'],
        ]],
        'risks' => [],
        'telegram' => ['messages' => 0],
        'data_quality' => [],
    ]);

    expect($text)
        ->toContain('M2 — Частичное ограничение')
        ->toContain('M3 — Линия закрыта')
        ->not->toContain('M1 — REGOLARE')
        ->not->toContain('M2 — Обычный режим')
        ->not->toContain('[HIGH]')
        ->not->toContain('[MEDIUM]')
        ->not->toContain('[INFO]');
});

it('hides empty positive and tomorrow sections in evening digest', function () {
    $text = app(TelegramDigestFormatter::class)->evening([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'forums' => [],
        'data_quality' => [],
    ]);

    expect($text)
        ->not->toContain('Что прошло хорошо')
        ->not->toContain('Проверить завтра');
});

it('keeps one freshest state per current line and removes duplicate line prefixes', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'staff' => ['working' => [['name' => 'Cleaner']], 'not_working' => [], 'shift' => ['total' => 1]],
        'calendar' => ['events' => []],
        'tasks' => ['items' => []],
        'mobility' => ['items' => [
            ['risk' => 'medium', 'district' => 'M1', 'type' => 'partial_closure', 'title' => 'M1 partial', 'summary' => 'M1 — частично ограничено движение', 'starts_at' => '2026-08-03'],
            ['risk' => 'high', 'district' => 'M1', 'type' => 'closure', 'title' => 'M1 closure', 'summary' => 'M1 — линия закрыта', 'starts_at' => '2026-08-03'],
        ]],
        'risks' => [],
        'telegram' => ['messages' => 0],
        'data_quality' => [],
    ]);

    expect($text)
        ->toContain('M1 — линия закрыта')
        ->not->toContain('частично ограничено движение')
        ->not->toContain('M1 — M1 —');
});

it('hides mobility presentation completely when only stale important events remain', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'staff' => ['working' => [['name' => 'Cleaner']], 'not_working' => [], 'shift' => ['total' => 1]],
        'calendar' => ['events' => []],
        'tasks' => ['items' => []],
        'mobility' => ['items' => [[
            'risk' => 'high', 'district' => 'M2', 'summary' => 'Линия закрыта',
            'starts_at' => '2026-08-01', 'ends_at' => '2026-08-02',
        ]]],
        'risks' => [['level' => 'high', 'code' => 'mobility_alert', 'source' => 'mobility', 'message' => 'transport']],
        'telegram' => ['messages' => 0],
        'data_quality' => [],
    ]);

    expect($text)
        ->not->toContain('Транспорт и ограничения')
        ->not->toContain('существенных транспортных ограничений')
        ->not->toContain('Уточнить влияние транспортного ограничения');
});

it('formats evening intelligence for humans without technical fields or duplicate events', function () {
    $item = [
        'event_key' => 'telegram:internal-key',
        'summary' => str_repeat('Длинное описание проблемы с замком. ', 12),
        'types' => ['problem'],
        'status' => 'open',
        'confidence' => 'high',
        'uncertainty' => null,
        'evidence' => [[
            'local_message_id' => 10,
            'telegram_message_id' => '20',
            'transition' => 'created',
            'occurred_at' => '2026-08-03T10:00:00+02:00',
        ]],
    ];
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'date' => '2026-08-03',
        'timezone' => 'Europe/Rome',
        'district' => ['key' => 'navigli', 'label' => 'Navigli'],
        'no_material_events' => false,
        'sections' => [
            ['key' => 'attention', 'label' => 'Требует внимания', 'items' => [$item]],
            ['key' => 'tomorrow', 'label' => 'На завтра', 'items' => [$item]],
        ],
    ]);

    expect($text)
        ->toContain('🌙 Navigli — итоги дня')
        ->toContain('За день:')
        ->toContain('Осталось на контроле:')
        ->not->toContain('Требует внимания')
        ->not->toContain('Качество')
        ->not->toContain('Риски и задержки')
        ->not->toContain('Возможно:')
        ->not->toContain('event_key')
        ->not->toContain('telegram:internal-key')
        ->not->toContain('Событие:')
        ->not->toContain('Доказательства:')
        ->not->toContain('статус')
        ->not->toContain('уверенность')
        ->not->toContain('transition')
        ->and(substr_count($text, '• '))->toBe(2)
        ->and(mb_strlen($text))->toBeLessThan(600);
});

it('removes a leading operational hashtag and keeps the human evening bullet concise', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'date' => '2026-07-23',
        'timezone' => 'Europe/Rome',
        'district' => ['key' => 'navigli', 'label' => 'Navigli'],
        'no_material_events' => false,
        'sections' => [[
            'key' => 'quality',
            'label' => 'Качество',
            'items' => [[
                'event_key' => 'telegram:quality',
                'summary' => '#сильныйбардак '.str_repeat('При осмотре квартиры обнаружен беспорядок. ', 8),
                'confidence' => 'high',
                'uncertainty' => null,
            ]],
        ]],
    ]);

    $bullet = collect(explode("\n", $text))->first(fn (string $line) => str_starts_with($line, '• '));

    expect($bullet)
        ->not->toContain('#сильныйбардак')
        ->and(mb_strlen($bullet))->toBeLessThanOrEqual(162);
});

it('renders a shift handoff with context, human wording and only open follow-ups', function () {
    $open = [
        'event_key' => 'open-problem',
        'summary' => 'Не работает замок',
        'context_label' => 'Via Roma 10',
        'types' => ['problem'],
        'status' => 'open',
        'confidence' => 'medium',
        'uncertainty' => 'нужно проверить',
    ];
    $resolved = [
        'event_key' => 'resolved-delay',
        'summary' => 'Я задержусь на 10 минут',
        'context_label' => 'Via Torino 5',
        'types' => ['delay', 'resolution'],
        'status' => 'resolved',
        'confidence' => 'high',
        'uncertainty' => null,
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [
            ['key' => 'attention', 'items' => [$open]],
            ['key' => 'resolved', 'items' => [$resolved]],
        ],
    ]);

    expect($text)
        ->toContain('Via Roma 10 — Возникла проблема: не работает замок.')
        ->toContain('Via Torino 5 — Сотрудник сообщил о задержке примерно на 10 минут.')
        ->toContain('Via Roma 10 — Нужно проверить решение: возникла проблема: не работает замок.')
        ->not->toContain('Via Torino 5 — Нужно проверить решение')
        ->not->toContain('Возможно:');
});

it('normalizes representative replay wording without inventing an apartment', function () {
    $items = [
        ['event_key' => 'arrival', 'summary' => 'Во сколько заезд?', 'types' => ['unanswered_question'], 'status' => 'open'],
        ['event_key' => 'hood', 'summary' => 'Вытяжка не работает на кухне', 'types' => ['problem'], 'status' => 'open'],
        ['event_key' => 'shutter', 'summary' => 'И в спальне не открываться ставня', 'types' => ['problem'], 'status' => 'open'],
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => $items]],
    ]);

    expect($text)
        ->toContain('Уточняли время заезда.')
        ->toContain('На кухне не работала вытяжка.')
        ->toContain('В спальне не открывалась ставня.')
        ->toContain('Нужно уточнить время заезда.')
        ->toContain('Нужно проверить, работает ли вытяжка на кухне.')
        ->not->toContain('Via ');
});

it('keeps a representative multi-type handoff concise without collapsing to one event type', function () {
    $items = [
        ['event_key' => 'question-1', 'summary' => 'Во сколько заезд?', 'types' => ['unanswered_question'], 'status' => 'open'],
        ['event_key' => 'question-2', 'summary' => 'Сколько им времени нужно?', 'types' => ['unanswered_question'], 'status' => 'open'],
        ['event_key' => 'problem-1', 'summary' => 'Вытяжка не работает на кухне', 'types' => ['problem'], 'status' => 'open'],
        ['event_key' => 'problem-2', 'summary' => 'И в спальне не открываться ставня', 'types' => ['problem'], 'status' => 'open'],
        ['event_key' => 'quality-1', 'summary' => 'Брак большого полотенца', 'types' => ['quality_issue'], 'status' => 'open'],
        ['event_key' => 'quality-2', 'summary' => 'Брак был в прошлой уборке', 'types' => ['quality_issue'], 'status' => 'open'],
        ['event_key' => 'delay', 'summary' => 'Я чуть задержусь', 'types' => ['delay'], 'status' => 'open'],
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => $items]],
    ]);

    expect($text)
        ->toContain('На кухне не работала вытяжка.')
        ->toContain('Обнаружен брак')
        ->toContain('Сотрудник сообщил о задержке.')
        ->toContain('Уточняли время заезда.')
        ->and(substr_count($text, '• '))->toBe(10)
        ->and(mb_strlen($text))->toBeLessThan(600);
});

it('omits templates, guidance and standalone resolutions from the human handoff', function () {
    $items = [
        ['event_key' => 'template', 'summary' => '#сильныйбардак При осмотре квартиры делаем 10-15 фото', 'types' => ['quality_issue'], 'status' => 'open'],
        ['event_key' => 'guidance', 'summary' => 'И промыла водой? Нужно всё хорошо промыть, чтобы средство не осталось', 'types' => ['problem'], 'status' => 'open'],
        ['event_key' => 'done', 'summary' => 'Готово', 'types' => ['resolution'], 'status' => 'resolved'],
    ];

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => $items]],
    ]);

    expect($text)
        ->toContain('• Значимых операционных событий не зафиксировано.')
        ->toContain('Открытых вопросов на конец дня нет.')
        ->not->toContain('10-15 фото')
        ->not->toContain('промыла водой')
        ->not->toContain('Готово');
});

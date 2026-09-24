<?php

use App\Services\Telegram\TelegramDigestFormatter;

it('formats an empty morning using only the supplied contract', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03', 'timezone' => 'Europe/Rome',
        'staff' => ['working' => [], 'not_working' => [], 'shift' => ['total' => 0]],
        'calendar' => ['events' => []], 'tasks' => ['items' => []], 'mobility' => ['items' => []],
        'risks' => [], 'telegram' => ['messages' => 0], 'data_quality' => [],
    ]);

    expect($text)->toContain('Утренняя сводка')
        ->toContain('За день значимых событий по доступным данным не обнаружено.')
        ->not->toContain('рейтинг')
        ->not->toContain('эффективность');
});

it('formats conservative evening problem and tomorrow sections', function () {
    $text = app(TelegramDigestFormatter::class)->evening([
        'date' => '2026-08-02', 'timezone' => 'Europe/Rome',
        'forums' => [[
            'chat_title' => 'Рабочий форум',
            'topics' => [[
                'topic_title' => 'Ключи', 'problem_signals' => 2, 'positive_signals' => 0,
                'possible_unanswered' => true, 'possible_resolved' => false, 'repeated_problem' => true,
            ]],
        ]],
        'data_quality' => [],
    ]);

    expect($text)->toContain('Итоги дня')
        ->toContain('Проблемы')
        ->toContain('Без ответа')
        ->toContain('Повторяющиеся сигналы')
        ->toContain('Проверить завтра')
        ->toContain('Рабочий форум / Ключи');
});

it('renders only meaningful normalized mobility events without severity labels', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03', 'timezone' => 'Europe/Rome',
        'staff' => ['working' => [], 'not_working' => [], 'shift' => ['total' => 0]],
        'calendar' => ['events' => []], 'tasks' => ['items' => []],
        'mobility' => ['items' => [
            ['risk' => 'info', 'district' => 'M1', 'summary' => 'REGOLARE'],
            ['risk' => 'low', 'district' => 'M2', 'summary' => 'Обычный режим'],
            ['risk' => 'medium', 'district' => 'M2', 'summary' => 'Частичное ограничение'],
            ['risk' => 'high', 'district' => 'M3', 'summary' => 'Линия закрыта'],
        ]],
        'risks' => [], 'telegram' => ['messages' => 0], 'data_quality' => [],
    ]);

    expect($text)->toContain('M2 — Частичное ограничение')
        ->toContain('M3 — Линия закрыта')
        ->not->toContain('M1 — REGOLARE')
        ->not->toContain('M2 — Обычный режим')
        ->not->toContain('[HIGH]')
        ->not->toContain('[MEDIUM]')
        ->not->toContain('[INFO]');
});

it('hides empty positive and tomorrow sections in evening digest', function () {
    $text = app(TelegramDigestFormatter::class)->evening([
        'date' => '2026-08-03', 'timezone' => 'Europe/Rome', 'forums' => [], 'data_quality' => [],
    ]);

    expect($text)->not->toContain('Что прошло хорошо')
        ->not->toContain('Проверить завтра');
});

it('keeps one freshest state per current line and removes duplicate line prefixes', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03', 'timezone' => 'Europe/Rome',
        'staff' => ['working' => [['name' => 'Cleaner']], 'not_working' => [], 'shift' => ['total' => 1]],
        'calendar' => ['events' => []], 'tasks' => ['items' => []],
        'mobility' => ['items' => [
            ['risk' => 'medium', 'district' => 'M1', 'type' => 'partial_closure', 'title' => 'M1 partial', 'summary' => 'M1 — частично ограничено движение', 'starts_at' => '2026-08-03'],
            ['risk' => 'high', 'district' => 'M1', 'type' => 'closure', 'title' => 'M1 closure', 'summary' => 'M1 — линия закрыта', 'starts_at' => '2026-08-03'],
        ]],
        'risks' => [], 'telegram' => ['messages' => 0], 'data_quality' => [],
    ]);

    expect($text)->toContain('M1 — линия закрыта')
        ->not->toContain('частично ограничено движение')
        ->not->toContain('M1 — M1 —');
});

it('hides stale mobility presentation when no current event remains', function () {
    $text = app(TelegramDigestFormatter::class)->morning([
        'date' => '2026-08-03', 'timezone' => 'Europe/Rome',
        'staff' => ['working' => [['name' => 'Cleaner']], 'not_working' => [], 'shift' => ['total' => 1]],
        'calendar' => ['events' => []], 'tasks' => ['items' => []],
        'mobility' => ['items' => [[
            'risk' => 'high', 'district' => 'M2', 'summary' => 'Линия закрыта',
            'starts_at' => '2026-08-01', 'ends_at' => '2026-08-02',
        ]]],
        'risks' => [['level' => 'high', 'code' => 'mobility_alert', 'source' => 'mobility', 'message' => 'transport']],
        'telegram' => ['messages' => 0], 'data_quality' => [],
    ]);

    expect($text)->not->toContain('Транспорт и ограничения')
        ->not->toContain('существенных транспортных ограничений')
        ->not->toContain('Уточнить влияние транспортного ограничения');
});

it('renders only Builder editorial sections in handoff order', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        // Raw ledger sections remain diagnostic data and are never rendered.
        'sections' => [['key' => 'attention', 'items' => [[
            'summary' => 'СТАРАЯ грязная посуда — открыто 8 дней',
        ]]]],
        'editorial_sections' => [
            ['key' => 'day', 'items' => [['event_key' => 'today', 'context_label' => 'Via Tosi 11', 'summary' => 'Сотрудник сообщил о задержке.']]],
            ['key' => 'resolved', 'items' => [['event_key' => 'resolved', 'context_label' => 'Via Savona 8', 'summary' => 'Проблема с доступом решена.']]],
            ['key' => 'positive', 'items' => [['event_key' => 'positive', 'context_label' => 'Via Y', 'summary' => 'Анна заметила дефект до заезда.']]],
            ['key' => 'attention', 'items' => [['event_key' => 'active', 'context_label' => 'Via Alfredo Panzini 13', 'summary' => 'Проблема с доступом: дверь не открывали.']]],
            ['key' => 'actions', 'items' => [['event_key' => 'active', 'context_label' => 'Via Alfredo Panzini 13', 'summary' => 'Проверить доступ в квартиру.']]],
        ],
    ]);

    expect($text)->toContain('🌙 Navigli — итоги дня')
        ->toContain('За день:')
        ->toContain('✅ Решено сегодня:')
        ->toContain('⭐ Хорошая работа:')
        ->toContain('🔄 Требует внимания:')
        ->toContain('Осталось сделать:')
        ->not->toContain('СТАРАЯ грязная посуда')
        ->not->toContain('Открыто')
        ->not->toContain('Переходящие проблемы')
        ->not->toContain('Открытых вопросов на конец дня нет.')
        ->not->toContain('⚠️ Повторяется')
        ->and(strpos($text, 'За день:'))->toBeLessThan(strpos($text, '✅ Решено сегодня:'))
        ->and(strpos($text, '✅ Решено сегодня:'))->toBeLessThan(strpos($text, '⭐ Хорошая работа:'))
        ->and(strpos($text, '⭐ Хорошая работа:'))->toBeLessThan(strpos($text, '🔄 Требует внимания:'))
        ->and(strpos($text, '🔄 Требует внимания:'))->toBeLessThan(strpos($text, 'Осталось сделать:'));
});

it('hides empty editorial sections and refuses to infer from raw ledger sections', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Navigli'],
        'sections' => [['key' => 'attention', 'items' => [[
            'summary' => 'Не работает дверь, проверьте срочно.', 'status' => 'open', 'types' => ['problem'],
        ]]]],
        'editorial_sections' => [],
    ]);

    expect($text)->toBe('🌙 Navigli — итоги дня')
        ->not->toContain('Не работает дверь')
        ->not->toContain('Осталось сделать:');
});

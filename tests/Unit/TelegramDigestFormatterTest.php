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

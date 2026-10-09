<?php

use App\Services\Telegram\TelegramDigestFormatter;
use Tests\TestCase;

uses(TestCase::class);

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

it('renders one daily problem card with source metadata and no legacy sections', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Como'], 'date' => '2026-09-30', 'timezone' => 'Europe/Rome',
        'daily_problems' => [[
            'event_key' => 'current', 'context_label' => 'Via Test 1', 'summary' => 'Не работает замок.',
            'author_name' => 'Test Worker', 'source_time' => '2026-09-30T08:42:00Z',
            'quote' => 'Не работает замок', 'source_url' => 'https://t.me/c/12345/42',
        ]],
        'editorial_sections' => [
            ['key' => 'carry_over', 'items' => [['summary' => 'Исторический дефект.']]],
            ['key' => 'actions', 'items' => [['summary' => 'Проверить замок.']]],
            ['key' => 'resolved', 'items' => [['summary' => 'Полотенце заменено.']]],
        ],
    ]);

    expect($text)->toBe("🌙 Como — проблемы за день · 30.09.2026\n\nЗа сегодня:\n\n• Via Test 1 — Не работает замок.\n  👤 Test Worker · 10:42\n  💬 «Не работает замок»\n  🔗 https://t.me/c/12345/42")
        ->not->toContain('Исторический дефект', 'Проверить замок', 'Полотенце заменено', 'Осталось сделать');
});

it('renders a clean empty state and never falls back to diagnostic sections', function (?string $district) {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => $district === null ? null : ['label' => $district],
        'date' => '2026-09-30', 'daily_problems' => [],
        'sections' => [['key' => 'attention', 'items' => [['summary' => 'Старый дефект.']]]],
        'editorial_sections' => [['key' => 'carry_over', 'items' => [['summary' => 'Старый дефект.']]]],
    ]);

    expect($text)->toBe('🌙 '.($district ?? 'TRIS')." — проблемы за день · 30.09.2026\n\nЗа сегодня:\n✅ Новых значимых событий не зафиксировано.");
})->with([null, 'Navigli']);

it('renders only current cards and omits dated carry-over with its source evidence', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'district' => ['label' => 'Como'], 'date' => '2026-09-30', 'timezone' => 'Europe/Rome',
        'daily_problems' => [[
            'event_key' => 'current', 'context_label' => 'Via Today', 'summary' => 'Не работает замок.',
            'quote' => 'Не работает замок', 'author_name' => 'Today Worker',
        ]],
        'editorial_sections' => [[
            'key' => 'carry_over', 'items' => [[
                'event_key' => 'old', 'context_label' => 'Via Old', 'summary' => 'Не работает свет.',
                'open_since' => '2026-09-24', 'next_action' => 'Проверить свет.',
                'author_name' => 'Old Worker', 'quote' => 'Старое сообщение',
            ]],
        ]],
    ]);

    expect($text)->toContain("За сегодня:\n", 'Via Today — Не работает замок.', 'Today Worker')
        ->not->toContain('Via Old', 'Не закрыто с 24.09.', 'Проверить свет.', 'Old Worker', 'Старое сообщение', '⚠️ Осталось с прошлых дней:');
});

it('omits human problem cards without a source quote or meaningful location', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'date' => '2026-09-30', 'daily_problems' => [[
            'event_key' => 'current', 'summary' => 'Не работает свет.',
            'author_name' => null, 'source_time' => null, 'quote' => null, 'source_url' => null,
        ]],
    ]);

    expect($text)->toBe("🌙 TRIS — проблемы за день · 30.09.2026\n\nЗа сегодня:\n✅ Новых значимых событий не зафиксировано.")
        ->not->toContain('👤', '🕒', '💬', '🔗', 'Unknown', '#');
});

it('escapes source text for the existing HTML Telegram transport', function () {
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence([
        'date' => '2026-09-30', 'daily_problems' => [[
            'event_key' => 'current', 'context_label' => 'Via Test', 'summary' => 'Не работает свет & вытяжка.',
            'author_name' => 'Test & Worker', 'quote' => 'Курьер ждёт кур&#039;ера',
        ]],
    ]);

    expect($text)->toContain('свет &amp; вытяжка', 'Test &amp; Worker', "Курьер ждёт кур'ера")
        ->not->toContain('&#039;');
});

<?php

use App\Services\Pulse\PulseComposer;
use App\Services\Pulse\PulseEngine;
use App\Services\Pulse\PulseFact;
use Carbon\CarbonImmutable;

it('selects no more than three active unique groups deterministically', function () {
    $now = CarbonImmutable::parse('2026-11-28 09:00', 'Europe/Rome');
    $facts = [
        new PulseFact('old', 'old', 0, '', 'expired', expiresAt: $now),
        new PulseFact('b', 'request', 3, '', 'B'),
        new PulseFact('a', 'request', 3, '', 'A'),
        new PulseFact('task', 'tasks', 2, '', 'tasks'),
        new PulseFact('urgent', 'overdue', 1, '', 'urgent'),
        new PulseFact('extra', 'extra', 4, '', 'extra'),
    ];
    $engine = new PulseEngine;
    expect(array_column($engine->select($facts, $now), 'id'))->toBe(['urgent', 'task', 'a'])
        ->and(array_column($engine->select(array_reverse($facts), $now), 'id'))->toBe(['urgent', 'task', 'a']);
});

it('renders stable text segments with a relative link and correct Russian plurals', function () {
    $now = CarbonImmutable::parse('2026-11-28 09:00', 'Europe/Rome');
    $segments = (new PulseComposer)->compose('Коке', [
        new PulseFact('tasks', 'tasks', 2, 'Сегодня у тебя ', '3 задачи', url: '/tasks', icon: 'task'),
    ], $now);
    expect(implode('', array_column($segments, 'text')))->toBe('Доброе утро, Коке! Сегодня у тебя 3 задачи.')
        ->and($segments[3]['action'])->toBe(['type' => 'navigate', 'url' => '/tasks'])
        ->and(PulseComposer::plural(11, 'задача', 'задачи', 'задач'))->toBe('задач')
        ->and(PulseComposer::plural(22, 'задача', 'задачи', 'задач'))->toBe('задачи');
});

it('does not invent a work schedule in an empty context', function () {
    $segments = (new PulseComposer)->compose('', [], CarbonImmutable::parse('2026-11-28 20:00', 'Europe/Rome'));
    expect(implode('', array_column($segments, 'text')))->toBe('Добрый вечер! Пока нет актуальной информации.');
});

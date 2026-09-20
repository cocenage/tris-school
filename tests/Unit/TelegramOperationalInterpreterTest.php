<?php

use App\Services\Telegram\TelegramAssistantClassifier;
use App\Services\Telegram\TelegramOperationalInterpreter;

function operationalInterpreter(): TelegramOperationalInterpreter
{
    return new TelegramOperationalInterpreter(new TelegramAssistantClassifier);
}

it('ignores empty and ordinary conversation', function () {
    $interpreter = operationalInterpreter();

    expect($interpreter->interpret('')['meaningful'])->toBeFalse()
        ->and($interpreter->interpret('Всем привет, хорошего дня')['reason_code'])->toBe('ordinary_conversation');
});

it('classifies every operational event type conservatively', function (string $text, string $type) {
    $decision = operationalInterpreter()->interpret($text);

    expect($decision['meaningful'])->toBeTrue()
        ->and($decision['types'])->toContain($type)
        ->and($decision['confidence'])->toBeIn(['medium', 'high']);
})->with([
    ['Не работает замок в квартире', 'problem'],
    ['Есть риск, что гость не попадёт в квартиру', 'risk'],
    ['Пожалуйста, привезите ключи в квартиру', 'request'],
    ['Я обещаю привезти ключи к 10:00', 'commitment'],
    ['Уже проверяю замок в квартире', 'action'],
    ['Замок исправлен, проблема решена', 'resolution'],
    ['Опоздаю на уборку на 30 минут', 'delay'],
    ['Кто привезёт ключи в квартиру?', 'request'],
    ['Квартира плохо убрана, осталась грязь', 'quality_issue'],
    ['Спасибо Анне, она отлично помогла с ключами', 'positive_contribution'],
]);

it('reuses existing assistant categories as an operational signal', function () {
    $decision = operationalInterpreter()->interpret('Завтра не смогу выйти на смену');

    expect($decision['meaningful'])->toBeTrue()
        ->and($decision['assistant_category'])->toBe(TelegramAssistantClassifier::CATEGORY_DAY_OFF)
        ->and($decision['types'])->toContain('request');
});

it('keeps uncertain conclusions visibly uncertain', function () {
    $decision = operationalInterpreter()->interpret('Кажется, с замком может быть проблема');

    expect($decision['meaningful'])->toBeTrue()
        ->and($decision['confidence'])->toBe('low')
        ->and($decision['uncertainty'])->not->toBeNull()
        ->and($decision['transition'])->not->toBe('resolved');
});

it('returns lifecycle and question signals without side effects', function () {
    $resolution = operationalInterpreter()->interpret('Всё исправили, вопрос решён');
    $question = operationalInterpreter()->interpret('Кто заберёт ключи из квартиры?');

    expect($resolution)->toMatchArray([
        'meaningful' => true,
        'role' => 'resolution',
        'transition' => 'resolved',
    ])->and($question['is_question'])->toBeTrue()
        ->and($question['role'])->toBe('question');
});

it('ignores generic thanks praise and emoji reactions', function (string $text) {
    $decision = operationalInterpreter()->interpret($text);

    expect($decision['meaningful'])->toBeFalse()
        ->and($decision['reason_code'])->toBe('ordinary_conversation');
})->with([
    'thanks' => ['Спасибо'],
    'praise' => ['Молодец)'],
    'emoji thanks' => ['🥰 спасибо'],
    'friendly thanks' => ['И тебе спасибо, хорошего дня 😉'],
]);

it('requires operational context for a high-confidence request', function () {
    $generic = operationalInterpreter()->interpret('@worker проверь пожалуйста');
    $contextual = operationalInterpreter()->interpret('Пожалуйста, проверь замок в квартире');

    expect($generic['meaningful'])->toBeFalse()
        ->and($generic['reason_code'])->toBe('insufficient_operational_signal')
        ->and($contextual['meaningful'])->toBeTrue()
        ->and($contextual['confidence'])->toBe('high');
});

it('gives defect language precedence over an action verb', function () {
    $decision = operationalInterpreter()->interpret('Начала проверять: мусор и сильная вонь в квартире');

    expect($decision['meaningful'])->toBeTrue()
        ->and($decision['primary_type'])->toBeIn(['problem', 'quality_issue'])
        ->and($decision['primary_type'])->not->toBe('action');
});

it('recognizes concrete helpful actions without requiring praise', function (string $text) {
    $decision = operationalInterpreter()->interpret($text);

    expect($decision['primary_type'])->toBe('positive_contribution')
        ->and($decision['confidence'])->toBe('high')
        ->and($decision['role'])->toBe('positive');
})->with([
    'defect before arrival' => ['Я заметила дефект белья до заезда и сразу сообщила об этом'],
    'early warning' => ['Я заранее сообщила о проблеме с бельём'],
    'self resolution' => ['Я сама решила проблему с доступом'],
    'helped colleague' => ['Я помогла коллеге решить проблему с ключами'],
    'prevented error' => ['Я предотвратила ошибку с ключами'],
    'documented issue' => ['Я подробно задокументировала проблему с замком'],
]);

it('does not mistake routine work or ungrounded praise for a positive contribution', function (string $text) {
    $decision = operationalInterpreter()->interpret($text);

    expect($decision['primary_type'])->not->toBe('positive_contribution');
})->with([
    'routine cleaning' => ['Я убрала квартиру'],
    'routine check-in' => ['Я пришла на смену'],
    'bare completion' => ['Готово'],
    'generic praise' => ['Анна молодец, спасибо!'],
    'routine praise' => ['Спасибо Анне, отлично убрала квартиру'],
    'negated help' => ['Я не помогла коллеге с ключами'],
]);

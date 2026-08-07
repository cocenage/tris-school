<?php

use App\Services\Telegram\TelegramAssistantClassifier;

it('classifies the supported Telegram categories', function () {
    $classifier = new TelegramAssistantClassifier();

    expect($classifier->classify('Завтра не смогу выйти, заболела.')['category'])
        ->toBe(TelegramAssistantClassifier::CATEGORY_ILLNESS)
        ->and($classifier->classify('Хочу взять отпуск с 10 по 15 число')['category'])
        ->toBe(TelegramAssistantClassifier::CATEGORY_VACATION)
        ->and($classifier->classify('Не работает приложение академии')['category'])
        ->toBe(TelegramAssistantClassifier::CATEGORY_TECHNICAL);
});

it('marks sensitive categories and avoids ordinary group chatter', function () {
    $classifier = new TelegramAssistantClassifier();

    expect($classifier->classify('Не получила зарплату')['sensitive'])->toBeTrue()
        ->and($classifier->isActivated(['text' => 'Всем привет, хорошего дня']))->toBeFalse()
        ->and($classifier->isActivated(['text' => 'Завтра не смогу выйти на смену']))->toBeTrue();
});

it('accepts explicit assistant activation methods', function () {
    $classifier = new TelegramAssistantClassifier();

    expect($classifier->isActivated(['text' => '/assistant']))->toBeTrue()
        ->and($classifier->isActivated([
            'text' => 'Помоги, пожалуйста',
            'reply_to_message' => ['from' => ['is_bot' => true]],
        ]))->toBeTrue()
        ->and($classifier->isActivated(['text' => 'Помоги @tris_bot'], 'tris_bot'))
        ->toBeTrue();
});

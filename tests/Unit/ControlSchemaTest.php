<?php

use App\Services\Controls\ControlSchema;
use Tests\TestCase;

uses(TestCase::class);

function controlSchemaFixture(array $rooms): ControlSchema
{
    return new ControlSchema(42, $rooms);
}

function controlSchemaQuestion(string $question, int $yesPoints = 1): array
{
    return [
        'question' => $question,
        'answer_type' => 'options',
        'answer_options_scored' => [
            ['value' => 'yes', 'label' => 'Да', 'points' => $yesPoints],
            ['value' => 'no', 'label' => 'Нет', 'points' => 0],
        ],
    ];
}

it('versions the complete ordered schema without changing its contents', function () {
    $schema = controlSchemaFixture([[
        'title' => 'Вход',
        'items' => [controlSchemaQuestion('Ключи на месте?'), controlSchemaQuestion('Дверь чистая?')],
    ]]);
    $reordered = controlSchemaFixture([[
        'title' => 'Вход',
        'items' => [controlSchemaQuestion('Дверь чистая?'), controlSchemaQuestion('Ключи на месте?')],
    ]]);

    expect($schema->version())->toMatch('/^[a-f0-9]{64}$/')
        ->and($schema->version())->not->toBe($reordered->version())
        ->and($schema->questionIds()[0][0])->toBe($reordered->questionIds()[0][1])
        ->and($schema->questionIds()[0][1])->toBe($reordered->questionIds()[0][0]);
});

it('binds an unchanged question by its full definition rather than its position or text alone', function () {
    $oldSchema = controlSchemaFixture([[
        'title' => 'Спальня',
        'items' => [controlSchemaQuestion('Постель подготовлена?'), controlSchemaQuestion('Свет работает?')],
    ]]);
    $newSchema = controlSchemaFixture([[
        'title' => 'Спальня',
        'items' => [
            controlSchemaQuestion('Постель подготовлена?', 2),
            controlSchemaQuestion('Свет работает?'),
            controlSchemaQuestion('Постель подготовлена?'),
        ],
    ]]);

    $oldIds = $oldSchema->questionIds();
    $newIds = $newSchema->questionIds();

    expect($oldIds[0][0])->not->toBe($newIds[0][0])
        ->and($oldIds[0][1])->toBe($newIds[0][1]);
});

it('maps only uniquely identified unchanged answers when schema versions differ', function () {
    $oldSchema = controlSchemaFixture([[
        'title' => 'Спальня',
        'items' => [controlSchemaQuestion('Постель подготовлена?'), controlSchemaQuestion('Свет работает?')],
    ]]);
    $newSchema = controlSchemaFixture([[
        'title' => 'Спальня',
        'items' => [controlSchemaQuestion('Новый вопрос?'), controlSchemaQuestion('Постель подготовлена?')],
    ]]);

    $mapped = $newSchema->mapAnswersFrom($oldSchema, [
        0 => [0 => ['selected' => 'yes'], 1 => ['selected' => 'no']],
    ]);

    expect($mapped['responses'][0][0]['selected'] ?? '')->toBe('')
        ->and($mapped['responses'][0][1]['selected'] ?? null)->toBe('yes')
        ->and($mapped['unmapped'])->toBe(1);
});

it('rejects unknown option values while preserving required and optional completion rules', function () {
    $optional = controlSchemaQuestion('Необязательный вопрос?');
    $optional['is_optional'] = true;
    $schema = controlSchemaFixture([[
        'title' => 'Вход',
        'items' => [
            controlSchemaQuestion('Обязательный вопрос?'),
            $optional,
            ['question' => 'Текстовый ответ', 'answer_type' => 'text'],
        ],
    ]]);

    $answers = [
        0 => [0 => ['selected' => 'yes'], 2 => ['custom' => 'Проверено']],
    ];
    $invalidAnswers = [
        0 => [0 => ['selected' => 'made-up'], 2 => ['custom' => 'Проверено']],
    ];

    expect($schema->validateAnswers($answers, requireComplete: true))->toBe([])
        ->and($schema->attachQuestionIds($answers)[0][0]['question_id'])->toBe($schema->questionIds()[0][0])
        ->and($schema->validateAnswers($invalidAnswers, requireComplete: true))->toHaveKey('answers.0.0');
});

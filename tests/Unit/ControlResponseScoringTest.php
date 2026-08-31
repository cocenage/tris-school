<?php

use App\Models\ControlResponse;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

function controlQuestion(array $overrides = []): array
{
    return array_merge([
        'question' => 'Проверка',
        'answer_type' => 'options',
        'answer_options_scored' => [
            ['value' => 'yes', 'label' => 'Да', 'points' => 1],
            ['value' => 'no', 'label' => 'Нет', 'points' => 0],
        ],
    ], $overrides);
}

it('scores positive answers without errors', function () {
    $schema = [['title' => 'Комната', 'items' => [controlQuestion()]]];

    expect(ControlResponse::calculateScores($schema, [[['selected' => 'yes']]]))
        ->toMatchArray([
            'total_points' => 1,
            'max_points' => 1,
            'penalty_points' => 0,
            'errors_count' => 0,
            'has_critical_failure' => false,
            'result_zone' => 'green',
        ]);
});

it('scores a negative answer as an ordinary error', function () {
    $schema = [['title' => 'Комната', 'items' => [controlQuestion()]]];

    expect(ControlResponse::calculateScores($schema, [[['selected' => 'no']]]))
        ->toMatchArray([
            'penalty_points' => 1,
            'errors_count' => 1,
            'has_critical_failure' => false,
            'result_zone' => 'yellow',
        ]);
});

it('identifies positive and negative scored options for corrective fields', function () {
    $question = controlQuestion();

    expect(ControlResponse::isNegativeAnswer($question, 'yes'))->toBeFalse()
        ->and(ControlResponse::isNegativeAnswer($question, 'no'))->toBeTrue()
        ->and(ControlResponse::isNegativeAnswer(['answer_type' => 'text'], 'no'))->toBeFalse();
});

it('marks explicit critical questions only when answered negatively', function () {
    $schema = [['title' => 'Комната', 'items' => [controlQuestion(['is_critical' => true])]]];

    expect(ControlResponse::calculateScores($schema, [[['selected' => 'yes']]])['has_critical_failure'])
        ->toBeFalse()
        ->and(ControlResponse::calculateScores($schema, [[['selected' => 'no']]])['has_critical_failure'])
        ->toBeTrue();
});

it('keeps legacy sleeping places critical rule', function () {
    $schema = [['title' => 'Спальные места', 'items' => [controlQuestion(), controlQuestion()]]];

    expect(ControlResponse::calculateScores($schema, [[['selected' => 'no'], ['selected' => 'yes']]])['has_critical_failure'])
        ->toBeTrue();
});

it('treats malformed response entries as empty instead of throwing', function () {
    $schema = [['title' => 'Комната', 'items' => [controlQuestion()]]];

    expect(fn () => ControlResponse::calculateScores($schema, [['broken']]))
        ->not->toThrow(Throwable::class);
});

it('resolves stored control media only when the file exists', function () {
    Storage::fake('public');
    Storage::disk('public')->put('controls/example.jpg', 'image');

    expect(ControlResponse::resolveMediaUrl([
        'disk' => 'public',
        'path' => 'controls/example.jpg',
    ]))->toContain('controls/example.jpg')
        ->and(ControlResponse::resolveMediaUrl([
            'disk' => 'public',
            'path' => 'controls/missing.jpg',
        ]))->toBeNull();
});

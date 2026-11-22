<?php

use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Telegram\AntigocciaCommandHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

function antigocciaValidAnswers(): array
{
    return collect(AntigocciaAssessment::SURFACES)->mapWithKeys(
        fn ($label, $key) => [$key => ['dirt' => 0, 'removal' => null]]
    )->all();
}

it('stores no-removal-required when a surface is clean', function () {
    $answers = antigocciaValidAnswers();
    $answers['mirrors'] = ['dirt' => 0, 'removal' => 1]; // stale client data

    $normalized = app(AntigocciaAssessment::class)->validate($answers, 'no', [], null);
    expect($normalized['mirrors'])->toBe(['dirt' => 0, 'removal' => null]);
});

it('requires a removal answer for contaminated surfaces', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_inside'] = ['dirt' => 2, 'removal' => null];

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, 'no', [], null))
        ->toThrow(ValidationException::class);
});

it('requires surface photos when contamination is not removed', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_walls'] = ['dirt' => 2, 'removal' => 2];

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, 'yes', [], null))
        ->toThrow(ValidationException::class);
});

it('accepts one surface photo as the proof for remaining contamination', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_walls'] = ['dirt' => 2, 'removal' => 2];

    $normalized = app(AntigocciaAssessment::class)->validate(
        $answers,
        'yes',
        ['shower_walls' => UploadedFile::fake()->image('wall.jpg')],
        null,
    );
    expect($normalized['shower_walls'])->toBe(['dirt' => 2, 'removal' => 2]);
});

it('requires a general photo when contamination remains but no surface scored failed', function () {
    $answers = antigocciaValidAnswers();

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, 'yes', [], null))
        ->toThrow(ValidationException::class);
});

it('recognizes the antigoccia Telegram command with correct bot mention', function () {
    config(['services.telegram.bot_username' => 'TrisAcademyBot']);
    $handler = app(AntigocciaCommandHandler::class);

    expect($handler->isCommand(['text' => '/antigoccia']))->toBeTrue()
        ->and($handler->isCommand(['text' => '/Antigoccia@TrisAcademyBot']))->toBeTrue()
        ->and($handler->isCommand(['text' => '/antigoccia@ForeignBot']))->toBeFalse()
        ->and($handler->isCommand(['text' => 'hello /antigoccia']))->toBeFalse();
});

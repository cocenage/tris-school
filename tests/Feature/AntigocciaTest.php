<?php

use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Telegram\AntigocciaCommandHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function antigocciaValidAnswers(): array
{
    return collect(AntigocciaAssessment::SURFACES)->mapWithKeys(
        fn ($label, $key) => [$key => ['dirt' => 0, 'removal' => null, 'remaining' => 'no']]
    )->all();
}

it('requires answers to all four surfaces, including remaining contamination', function () {
    $answers = antigocciaValidAnswers();
    $answers['mirrors']['remaining'] = '';

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);
});

it('normalizes removal as not required for a clean surface', function () {
    $answers = antigocciaValidAnswers();
    $answers['mirrors']['removal'] = 1; // stale client data

    $normalized = app(AntigocciaAssessment::class)->validate($answers, []);
    expect($normalized['mirrors'])->toBe(['dirt' => 0, 'removal' => null, 'remaining' => 'no']);
});

it('requires removal answer when a surface is contaminated', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_inside'] = ['dirt' => 2, 'removal' => null, 'remaining' => 'no'];

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);
});

it('requires photo on the exact surface where contamination remains', function () {
    $answers = antigocciaValidAnswers();
    $answers['mirrors'] = ['dirt' => 1, 'removal' => 1, 'remaining' => 'yes'];

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);

    // A photo of another surface cannot satisfy mirrors.
    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, [
        'shower_inside' => UploadedFile::fake()->image('inside.jpg'),
    ]))->toThrow(ValidationException::class);

    $result = app(AntigocciaAssessment::class)->validate($answers, [
        'mirrors' => UploadedFile::fake()->image('mirror.jpg'),
    ]);
    expect($result['mirrors']['remaining'])->toBe('yes');
});

it('requires a positive remaining answer and a photo when dirt cannot be removed', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_walls'] = ['dirt' => 2, 'removal' => 2, 'remaining' => 'no'];
    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);

    $answers['shower_walls']['remaining'] = 'yes';
    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);
    $result = app(AntigocciaAssessment::class)->validate($answers, [
        'shower_walls' => UploadedFile::fake()->image('wall.jpg'),
    ]);
    expect($result['shower_walls'])->toBe(['dirt' => 2, 'removal' => 2, 'remaining' => 'yes']);
});

it('allows independent answers for all four simultaneously visible surfaces', function () {
    $answers = antigocciaValidAnswers();
    $answers['shower_outside'] = ['dirt' => 2, 'removal' => 1, 'remaining' => 'no'];
    $answers['shower_inside'] = ['dirt' => 1, 'removal' => 0, 'remaining' => 'no'];
    $result = app(AntigocciaAssessment::class)->validate($answers, []);
    expect(array_keys($result))->toBe(array_keys(AntigocciaAssessment::SURFACES))
        ->and($result['shower_outside']['removal'])->toBe(1)
        ->and($result['shower_inside']['removal'])->toBe(0);
});

it('recognizes the antigoccia Telegram command with correct bot mention', function () {
    config(['services.telegram.bot_username' => 'TrisAcademyBot']);
    $handler = app(AntigocciaCommandHandler::class);

    expect($handler->isCommand(['text' => '/antigoccia']))->toBeTrue()
        ->and($handler->isCommand(['text' => '/Antigoccia@TrisAcademyBot']))->toBeTrue()
        ->and($handler->isCommand(['text' => '@TrisAcademyBot /antigoccia']))->toBeTrue()
        ->and($handler->isCommand(['text' => '@ForeignBot /antigoccia']))->toBeFalse()
        ->and($handler->isCommand(['text' => '/antigoccia@ForeignBot']))->toBeFalse()
        ->and($handler->isCommand(['text' => 'hello /antigoccia']))->toBeFalse();
});

it('sends the mini app link to the same forum topic', function () {
    config(['services.telegram.bot_username' => 'TrisAcademyBot', 'services.telegram.bot_token' => 'test-token']);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 25]])]);

    $response = app(AntigocciaCommandHandler::class)->handle([
        'chat' => ['id' => '-1001'], 'message_id' => 10, 'message_thread_id' => 12,
    ]);

    expect($response->getStatusCode())->toBe(200);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['message_thread_id'] === 12
        && $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://t.me/TrisAcademyBot?startapp=antigoccia&mode=compact');
});

it('returns an inline result that opens the mini app', function () {
    config(['services.telegram.bot_username' => 'TrisAcademyBot', 'services.telegram.bot_token' => 'test-token']);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $response = app(AntigocciaCommandHandler::class)->handleInline(['id' => 'query-1', 'query' => 'antigoccia']);

    expect($response->getStatusCode())->toBe(200);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerInlineQuery')
        && $request['results'][0]['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://t.me/TrisAcademyBot?startapp=antigoccia&mode=compact');
});

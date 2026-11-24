<?php

use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Telegram\AntigocciaCommandHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function antigocciaValidAnswers(): array
{
    return collect(AntigocciaAssessment::SURFACES)->mapWithKeys(
        fn ($label, $key) => [$key => ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no']]
    )->all();
}

it('requires all three independent answers for every surface, even with no dirt', function (): void {
    foreach (array_keys(AntigocciaAssessment::SURFACES) as $surface) {
        foreach (['dirt', 'removal', 'remaining'] as $field) {
            $answers = antigocciaValidAnswers();
            $answers[$surface][$field] = null;
            expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
                ->toThrow(ValidationException::class);
        }
    }
});

it('allows every removal option regardless of surface dirt score', function (): void {
    foreach ([0, 1, 2] as $dirt) {
        foreach (array_keys(AntigocciaAssessment::REMOVAL_OPTIONS) as $removal) {
            $answers = antigocciaValidAnswers();
            $answers['mirrors'] = ['dirt' => $dirt, 'removal' => $removal, 'remaining' => 'no'];
            $normalized = app(AntigocciaAssessment::class)->validate($answers, []);
            expect($normalized['mirrors'])->toBe([
                'dirt' => $dirt, 'removal' => $removal, 'remaining' => 'no',
            ]);
        }
    }
});

it('keeps the answer about remaining contamination independent even when removal is hard', function (): void {
    $answers = antigocciaValidAnswers();
    $answers['shower_walls'] = ['dirt' => 2, 'removal' => 'hard', 'remaining' => 'no'];
    $normalized = app(AntigocciaAssessment::class)->validate($answers, []);

    expect($normalized['shower_walls'])->toBe([
        'dirt' => 2, 'removal' => 'hard', 'remaining' => 'no',
    ]);
});

it('requires a photo on the exact surface with remaining contamination', function (): void {
    $answers = antigocciaValidAnswers();
    $answers['mirrors'] = ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'yes'];

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, [
        'shower_outside' => UploadedFile::fake()->image('outside.jpg'),
    ]))->toThrow(ValidationException::class);

    $normalized = app(AntigocciaAssessment::class)->validate($answers, [
        'mirrors' => UploadedFile::fake()->image('mirror.jpg'),
    ]);

    expect($normalized['mirrors']['remaining'])->toBe('yes');
});

it('accepts optional photos for surfaces without remaining contamination', function (): void {
    $answers = antigocciaValidAnswers();
    $normalized = app(AntigocciaAssessment::class)->validate($answers, [
        'shower_inside' => UploadedFile::fake()->image('inside.png'),
    ]);

    expect($normalized['shower_inside'])->toBe(['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no']);
});

it('does not allow new answers to reuse ambiguous legacy numeric removal values', function (): void {
    $answers = antigocciaValidAnswers();
    $answers['mirrors']['removal'] = 1;

    expect(fn () => app(AntigocciaAssessment::class)->validate($answers, []))
        ->toThrow(ValidationException::class);
});

it('preserves the meaning of legacy removal scores in analytics and history', function (): void {
    expect(AntigocciaAssessment::removalLabel(1))->toBe('Трудно (старая анкета)')
        ->and(AntigocciaAssessment::removalLabel(2))->toBe('Не удалились (старая анкета)')
        ->and(AntigocciaAssessment::removalLabel('medium'))->toContain('Средне')
        ->and(AntigocciaAssessment::removalLabel('no_dirt'))->toContain('Поверхность всё равно протёрта')
        ->and(AntigocciaAssessment::isDifficultRemoval('medium'))->toBeTrue()
        ->and(AntigocciaAssessment::isDifficultRemoval('hard'))->toBeTrue()
        ->and(AntigocciaAssessment::isDifficultRemoval(1))->toBeTrue()
        ->and(AntigocciaAssessment::isHardRemoval('hard'))->toBeTrue()
        ->and(AntigocciaAssessment::isHardRemoval(2))->toBeTrue()
        ->and(AntigocciaAssessment::isHardRemoval('medium'))->toBeFalse()
        ->and(AntigocciaAssessment::removalIndicator('no_dirt')['label'])->toBe('—');
});

it('allows independent answers across four simultaneously visible surfaces', function (): void {
    $answers = antigocciaValidAnswers();
    $answers['shower_outside'] = ['dirt' => 2, 'removal' => 'medium', 'remaining' => 'no'];
    $answers['shower_inside'] = ['dirt' => 1, 'removal' => 'easy', 'remaining' => 'no'];
    $answers['shower_walls'] = ['dirt' => 0, 'removal' => 'hard', 'remaining' => 'no'];

    $normalized = app(AntigocciaAssessment::class)->validate($answers, []);
    expect(array_keys($normalized))->toBe(array_keys(AntigocciaAssessment::SURFACES))
        ->and($normalized['shower_outside']['removal'])->toBe('medium')
        ->and($normalized['shower_inside']['removal'])->toBe('easy')
        ->and($normalized['shower_walls']['removal'])->toBe('hard');
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

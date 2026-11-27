<?php

use App\Services\Antigoccia\AntigocciaAssessment;

it('shows only Antigoccia and the Focus entry on the Controls page', function (): void {
    $checks = file_get_contents(base_path('resources/views/components/⚡page-checks.blade.php'));

    expect($checks)
        ->toContain("route('antigoccia.index')")
        ->toContain('Антикапля')
        ->toContain('Контроль покрытия')
        ->toContain('focus-visible:outline')
        ->not->toContain('wire:click="submitAntigoccia"')
        ->toContain("route('page-checks.control.focus-v2')")
        ->not->toContain("route('page-checks.control')")
        ->not->toContain("route('page-coachings')")
        ->not->toContain("route('page-profile.checks')")
        ->not->toContain("route('page-profile.all-checks')");
});

it('provides progress, severity, photo and saved states without changing independent answers', function (): void {
    $view = file_get_contents(base_path('resources/views/components/antigoccia/⚡page-antigoccia.blade.php'));

    expect($view)
        ->toContain('getCompletionProperty(): array')
        ->toContain("'completed' => " . '$completed')
        ->toContain("'needs_photo' => " . '$needsPhoto')
        ->toContain("'has_photo' => " . '$hasPhoto')
        ->toContain("'label' => " . '$needsPhoto')
        ->toContain('role="progressbar"')
        ->toContain('Нужно фото')
        ->toContain('Есть остатки')
        ->toContain('В процессе')
        ->toContain('Не начато')
        ->toContain('Заполнено')
        ->toContain('wire:click="setDirt(')
        ->toContain('wire:click="setRemoval(')
        ->toContain('wire:model.live="answers.{{ $surface }}.remaining"')
        ->toContain('wire:model="photos.{{ $surface }}"')
        ->toContain('temporaryUrl()')
        ->toContain('wire:click="removePhoto(')
        ->toContain('wire:loading wire:target="photos.{{ $surface }}"')
        ->toContain('wire:submit="submit"')
        ->toContain('wire:loading.attr="disabled" wire:target="submit,photos.*"')
        ->toContain('Анкета сохранена')
        ->toContain('wire:click="newForm"')
        ->toContain('wire:click="requestDelete(')
        ->toContain('wire:click="deleteMyReport"')
        ->toContain('wire:click="cancelDelete"')
        ->toContain('Ничего не найдено')
        ->not->toContain('wire:click="setRemaining(');
});

it('keeps all four surfaces and the four independent removal choices', function (): void {
    expect(AntigocciaAssessment::SURFACES)->toHaveCount(4)
        ->and(array_keys(AntigocciaAssessment::REMOVAL_OPTIONS))->toBe(['easy', 'medium', 'hard', 'no_dirt']);

    $view = file_get_contents(base_path('resources/views/components/antigoccia/⚡page-antigoccia.blade.php'));

    expect($view)
        ->toContain('AntigocciaAssessment::SURFACES as $surface => $label')
        ->toContain('AntigocciaAssessment::REMOVAL_OPTIONS as $value => $option')
        ->toContain('in_array($dirt, [0, 1, 2], true)')
        ->toContain("in_array(" . '$remaining' . ", ['yes', 'no'], true)")
        ->toContain("isset(" . '$this->photos[$surface]' . ")")
        ->toContain('Фото поверхности');
});


it('compiles the actual Filament statistics Blade into valid PHP', function (): void {
    $source = file_get_contents(resource_path('views/filament/pages/antigoccia-statistics.blade.php'));
    $compiled = app('blade.compiler')->compileString($source);

    // Static markup checks do not detect a PHP syntax error produced by
    // Blade when inline and block PHP directives are mixed.
    expect(fn () => token_get_all($compiled, TOKEN_PARSE))->not->toThrow(\ParseError::class);
});

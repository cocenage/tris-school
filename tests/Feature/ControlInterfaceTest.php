<?php

it('keeps control navigation anchored to the internal scroll container', function () {
    $view = file_get_contents(base_path('resources/views/components/forms/⚡page-control.blade.php'));

    expect($view)
        ->toContain('id="control-scroll-area" data-control-scroll-area')
        ->toContain('data-control-sticky-nav')
        ->toContain('data-control-room-nav=')
        ->toContain('data-control-room-section=')
        ->toContain('data-control-anchor="question-')
        ->toContain('Открыть инструкцию')
        ->toContain('Сбросить контроль')
        ->toContain('x-model="query"')
        ->not->toContain('Только незаполненные')
        ->not->toContain('Следующий незаполненный')
        ->toContain('Что исправить')
        ->toContain('Ошибка уже повторялась?')
        ->toContain('Что нужно делать иначе?')
        ->toContain('Проверить ещё раз?')
        ->toContain('setCorrectiveBoolean')
        ->toContain("'corrective' => \$this->correctiveDefaults()")
        ->toContain("is_bool(\$corrective['repeats'])")
        ->toContain("is_bool(\$corrective['recheck'])")
        ->toContain("trim((string) (\$corrective['action'] ?? '')) === ''")
        ->toContain("'responses' => \$this->normalizedAnswers(\$this->answers)")
        ->toContain('array_replace_recursive($this->answers, $draft->responses)')
        ->toContain('getReviewProblemsProperty')
        ->toContain('scroll-padding-top: var(--control-sticky-offset)')
        ->toContain('area.scrollTo({ top: Math.max(0, top), behavior: \'smooth\' })')
        ->not->toContain('scrollIntoView(');
});

it('keeps the optional control comment compact while preserving existing text', function () {
    $view = file_get_contents(base_path('resources/views/components/forms/⚡page-control.blade.php'));

    expect($view)
        ->toContain('x-on:control-open-comment.window="commentOpen = true"')
        ->toContain('x-show="commentOpen"')
        ->toContain('wire:model.blur="comment"');
});

it('renders corrective metadata on result and Filament views without requiring a new table', function () {
    $result = file_get_contents(base_path('resources/views/components/profile/⚡page-check-result.blade.php'));
    $infolist = file_get_contents(base_path('app/Filament/Resources/ControlResponses/Schemas/ControlResponseInfolist.php'));

    expect($result)
        ->toContain('Корректирующие действия')
        ->toContain('Повторный контроль')
        ->toContain("['corrective']")
        ->and($infolist)
        ->toContain('ControlResponse::resolveMediaUrl')
        ->toContain('Корректирующие действия');
});

it('uses the sent status consistently in the Filament response form', function () {
    $form = file_get_contents(base_path('app/Filament/Resources/ControlResponses/Schemas/ControlResponseForm.php'));

    expect($form)
        ->toContain('ControlResponse::STATUS_SENT')
        ->not->toContain("default('submitted')");
});

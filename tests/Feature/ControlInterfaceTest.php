<?php

it('keeps control navigation anchored to the internal scroll container', function () {
    $view = file_get_contents(base_path('resources/views/components/forms/⚡page-control.blade.php'));

    expect($view)
        ->toContain('id="control-scroll-area" data-control-scroll-area')
        ->toContain('toggleRoomLocally({{ $roomIndex }})')
        ->toContain(':aria-expanded="(openRoomIndex === {{ $roomIndex }}).toString()"')
        ->toContain('x-show="openRoomIndex === {{ $roomIndex }}"')
        ->not->toContain('toggleRoomAfterSave(roomIndex)')
        ->toContain('class="grid gap-[8px]" x-data="{ selected: @entangle(')
        ->not->toContain('choose(); save()')
        ->toContain('data-control-room-section=')
        ->toContain('data-control-anchor="question-')
        ->toContain('Открыть инструкцию')
        ->toContain('Сбросить контроль')
        ->toContain("window.dispatchEvent(new CustomEvent('control-reset-request'))")
        ->toContain('x-on:control-reset-request.window="resetFormFromMenu()"')
        ->toContain("\$this->incompleteQuestionsCount > 0 ? 'Дальше' : 'Проверить контроль'")
        ->toContain("\$this->goToQuestion(\$nextQuestion['room'], \$nextQuestion['question'])")
        ->toContain('x-model="query"')
        ->toContain('min-h-[56px] w-full')
        ->toContain('whitespace-normal break-words leading-[1.35]')
        ->toContain("@if(\$type === 'text' || \$type === 'both')")
        ->toContain("correctiveOpen: @js(\$errors->has('answers.'.\$roomIndex.'.'.\$questionIndex))")
        ->toContain('x-on:control-scroll.window="if ($event.detail.type === \'question\'')
        ->toContain('x-show="correctiveOpen"')
        ->toContain('min-h-[44px] rounded-full')
        ->toContain('mediaUploads: {}')
        ->toContain('startMediaUpload(key, input, room, question, files)')
        ->toContain('cancelMediaUpload(key, room, question, id)')
        ->toContain('controlUploadCompressedPhotos(')
        ->toContain('cancelUpload(property')
        ->toContain('Upload timed out')
        ->toContain("mediaUploads['{{ \$roomIndex }}.{{ \$questionIndex }}'] || []")
        ->toContain('Дождитесь завершения загрузки файлов')
        ->toContain('wire:click="goToQuestion({{ $issue[\'room\'] }}, {{ $issue[\'question\'] }})"')
        ->toContain('Отправить контроль');

    expect($view)->not->toContain('data-control-sticky-nav');
    expect($view)->not->toContain('data-control-room-nav=');
    expect($view)->not->toContain('Только незаполненные');
    expect($view)->not->toContain('Следующий незаполненный');

    expect($view)
        ->toContain('Что исправить')
        ->toContain('Ошибка уже повторялась?')
        ->toContain('Что нужно делать иначе?')
        ->toContain('Проверить ещё раз?')
        ->toContain('TODO: Keep corrective details available for a future UI; hidden in the current Classic form.')
        ->toContain('setCorrectiveBoolean')
        ->toContain("'corrective' => \$this->correctiveDefaults()")
        ->toContain("'action' => mb_substr(trim((string) (\$corrective['action'] ?? '')), 0, 2000)")
        ->toContain("'responses' => \$responses")
        ->toContain('getDraftPayload(')
        ->toContain('array_replace_recursive($this->answers, $draft->responses)')
        ->toContain('getReviewProblemsProperty')
        ->toContain('scroll-padding-top: var(--control-sticky-offset)')
        ->toContain('class="pb-[16px]"')
        ->toContain('class="absolute inset-0 h-full w-full cursor-pointer opacity-0"')
        ->toContain('x-on:change="startMediaUpload(')
        ->not->toContain('class="sr-only"')
        ->not->toContain('pb-[120px]')
        ->not->toContain('wire:click="resetControl"')
        ->toContain('saveRevision++')
        ->toContain('scheduleAutosave(delay = 1500)')
        ->toContain('if (this.saveRevision > this.attemptedSaveRevision) this.scheduleAutosave(0)')
        ->toContain('this.saveRevision === revision')
        ->toContain('this.persistedSaveRevision = Math.max(this.persistedSaveRevision, revision)')
        ->toContain('result === \'saved\' || result === \'idle\'')
        ->toContain('clientDraftState === \'error\'')
        ->toContain('clientDraftState === \'saving\'')
        ->toContain('clientDraftState === \'dirty\'')
        ->toContain('await this.flushPendingChanges()')
        ->toContain('x-bind:disabled="pendingUploads > 0 || hasUnconfirmedMedia || submissionInFlight || controlActionInFlight"')
        ->toContain("\$problem['selected_label'] ?? '—'")
        ->not->toContain('@js($isSubmitting)')
        ->not->toContain("\$problem['answer'] ?? '—'")
        ->not->toContain('wire:click="setAnswer(')
        ->not->toContain('wire:target="setAnswer"')
        ->toContain("area.scrollTo({ top: Math.max(0, top), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })")
        ->not->toContain('scrollIntoView(');
});

it('keeps Focus room navigation in Alpine and provides one room page at a time', function () {
    $view = file_get_contents(base_path('resources/views/components/forms/⚡page-control-focus.blade.php'));

    expect($view)
        ->toContain("activeRoomIndex: @entangle('currentRoomIndex')")
        ->toContain('x-on:click="nextRoom()"')
        ->toContain('x-on:click="previousRoom()"')
        ->toContain('x-if="activeRoomIndex === {{ $roomIndex }}"')
        ->toContain('env(safe-area-inset-bottom)')
        ->toContain('scrollToActiveRoom()')
        ->not->toContain('wire:click="goToRoom({{ $roomIndex }})"');
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
        ->toContain('mediaItems()')
        ->toContain('renderMedia($record)')
        ->toContain('Корректирующие действия');
});

it('uses the sent status consistently in the Filament response form', function () {
    $form = file_get_contents(base_path('app/Filament/Resources/ControlResponses/Schemas/ControlResponseForm.php'));

    expect($form)
        ->toContain('ControlResponse::STATUS_SENT')
        ->not->toContain("default('submitted')");
});

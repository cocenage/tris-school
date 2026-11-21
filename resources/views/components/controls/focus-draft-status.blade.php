<div wire:key="focus-draft-status" class="text-xs">
    <p x-show="clientDraftState !== 'idle'" x-cloak class="leading-5" :class="clientDraftState === 'error' ? 'text-[#B42318]' : 'text-[#596476]'" role="status" aria-live="polite">
        <span x-show="pendingComments === 0 && clientDraftState === 'dirty'">Изменения не сохранены</span>
        <span x-show="pendingComments > 0 || clientDraftState === 'saving'">Сохранение выполняется…</span>
        <span x-show="pendingComments === 0 && clientDraftState === 'saved'">Изменения сохранены</span>
        <span x-show="pendingComments === 0 && clientDraftState === 'error'" x-text="clientDraftError"></span>
    </p>
    <button x-show="clientDraftState === 'error' || Object.keys(failedComments).length > 0" type="button" x-on:click="retryDraft()" x-cloak class="min-h-11 text-sm font-semibold text-[#285B86]">Повторить сохранение</button>
</div>

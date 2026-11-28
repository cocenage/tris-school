@props(['label' => 'Просмотр вложений'])
<dialog x-ref="viewer" aria-label="{{ $label }}"
    x-on:close="reset()" x-on:click.self="close()" x-on:keydown="handleKey($event)"
    class="tris-media-viewer fixed inset-0 m-0 h-[100dvh] max-h-none w-full max-w-none overflow-y-auto border-0 bg-[#111111] text-white backdrop:bg-black/90">
    <template x-if="current">
        <div class="flex min-h-[100dvh] flex-col px-4 pt-[max(16px,env(safe-area-inset-top))] pb-[max(16px,env(safe-area-inset-bottom))]">
            <div class="flex shrink-0 items-center justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold" x-text="title"></div>
                    <div class="text-xs text-white/70" aria-live="polite" x-text="`${index + 1} / ${items.length}`"></div>
                </div>
                <button type="button" x-on:click="close()" autofocus aria-label="Закрыть просмотр" class="tris-mobile-press grid size-11 shrink-0 place-items-center rounded-full bg-white/10">
                    <x-heroicon-o-x-mark class="size-5" />
                </button>
            </div>
            <div class="grid min-h-0 flex-1 touch-pan-y touch-pinch-zoom place-items-center py-4"
                x-on:pointerdown="beginSwipe($event)" x-on:pointerup="endSwipe($event)" x-on:pointercancel="cancelSwipe()">
                <template x-if="current.kind === 'image' && !failed">
                    <img x-bind:src="current.url" x-bind:alt="current.caption || 'Фото'" draggable="false" x-on:error="failed = true" class="max-h-[72dvh] max-w-full object-contain" />
                </template>
                <template x-if="current.kind === 'video' && !failed">
                    <video x-bind:src="current.url" controls playsinline preload="none" x-on:error="failed = true" class="max-h-[72dvh] w-full"></video>
                </template>
                <div x-show="failed" x-cloak class="text-center text-sm text-white/80">Не удалось открыть файл. Попробуйте скачать оригинал.</div>
            </div>
            <div class="shrink-0 space-y-3">
                <div x-show="current.caption" x-text="current.caption" class="break-words text-center text-sm text-white/75"></div>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" x-on:click="step(-1)" x-bind:disabled="index === 0" aria-label="Предыдущее вложение" class="tris-mobile-press grid size-11 place-items-center rounded-full bg-white/10 disabled:opacity-30"><x-heroicon-o-chevron-left class="size-5" /></button>
                    <a x-bind:href="current.url + (current.url.includes('?') ? '&' : '?') + 'download=1'" class="tris-mobile-press flex min-h-11 items-center gap-2 rounded-full bg-white/10 px-4 text-sm"><x-heroicon-o-arrow-down-tray class="size-4" /> Скачать</a>
                    <button type="button" x-on:click="step(1)" x-bind:disabled="index === items.length - 1" aria-label="Следующее вложение" class="tris-mobile-press grid size-11 place-items-center rounded-full bg-white/10 disabled:opacity-30"><x-heroicon-o-chevron-right class="size-5" /></button>
                </div>
            </div>
        </div>
    </template>
</dialog>

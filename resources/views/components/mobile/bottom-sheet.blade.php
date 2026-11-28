@props(['title', 'ref' => 'sheet'])
<dialog x-ref="{{ $ref }}" aria-label="{{ $title }}"
    {{ $attributes->class(['fixed inset-x-0 top-auto bottom-0 mx-auto mb-0 max-h-[85dvh] w-full max-w-[768px] overflow-hidden rounded-t-[28px] bg-white p-0 text-[#171717] backdrop:bg-black/35']) }}
    x-on:click.self="$el.close()">
    <div class="flex max-h-[85dvh] flex-col">
        <div class="shrink-0 px-5 pt-3 pb-3"
            x-data="{ start: 0 }" x-on:touchstart.passive="start = $event.touches[0].clientY"
            x-on:touchend.passive="if ($event.changedTouches[0].clientY - start > 70) $el.closest('dialog').close()">
            <div class="mx-auto mb-4 h-1 w-9 rounded-full bg-[#D0D0D0]"></div>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold">{{ $title }}</h2>
                @isset($actions){{ $actions }}@endisset
                <button type="button" aria-label="Закрыть" x-on:click="$el.closest('dialog').close()" class="grid size-10 shrink-0 place-items-center rounded-full bg-[#F2F2F2]">
                    <x-heroicon-o-x-mark class="size-5" />
                </button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-4">{{ $slot }}</div>
        @isset($footer)
            <div class="shrink-0 border-t border-[#EEEEEE] px-5 pt-3 pb-[max(16px,env(safe-area-inset-bottom))]">{{ $footer }}</div>
        @endisset
    </div>
</dialog>

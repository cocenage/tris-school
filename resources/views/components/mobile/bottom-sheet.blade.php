@props(['title', 'ref' => 'sheet'])
<dialog x-ref="{{ $ref }}" x-data="mobileBottomSheet" aria-label="{{ $title }}"
    data-state="closed" x-bind:data-state="state" x-bind:style="{ '--sheet-offset': offset + 'px' }"
    {{ $attributes->class(['tris-mobile-bottom-sheet fixed inset-x-0 top-auto bottom-0 mx-auto mb-0 max-h-[85dvh] w-full max-w-[768px] overflow-hidden rounded-t-[28px] bg-white p-0 text-[#171717]']) }}
    x-on:tris-sheet-open.stop="open()" x-on:click.self="close()" x-on:cancel.prevent="close()" x-on:close="reset()">
    <div class="flex max-h-[85dvh] flex-col">
        <div class="shrink-0 px-5 pb-3">
            <div class="flex h-8 touch-none cursor-grab items-center justify-center select-none"
                x-on:pointerdown="startDrag($event)" x-on:pointermove="moveDrag($event)"
                x-on:pointerup="endDrag($event)" x-on:pointercancel="cancelDrag()" x-on:lostpointercapture="cancelDrag()">
                <div class="h-1 w-9 rounded-full bg-[#D0D0D0]"></div>
            </div>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold">{{ $title }}</h2>
                @isset($actions){{ $actions }}@endisset
                <button type="button" aria-label="Закрыть" x-on:click="close()" class="tris-mobile-press grid size-10 shrink-0 place-items-center rounded-full bg-[#F2F2F2]">
                    <x-heroicon-o-x-mark class="size-5" />
                </button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-[max(16px,env(safe-area-inset-bottom))]">{{ $slot }}</div>
        @isset($footer)
            <div class="shrink-0 border-t border-[#EEEEEE] px-5 pt-3 pb-[max(16px,env(safe-area-inset-bottom))]">{{ $footer }}</div>
        @endisset
    </div>
</dialog>

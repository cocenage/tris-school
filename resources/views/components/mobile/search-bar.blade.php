@props(['placeholder' => 'Поиск'])
<div class="min-w-0 flex-1" x-data="{ hasValue: false }">
    <label class="tris-mobile-search">
        <x-heroicon-o-magnifying-glass class="size-[18px] shrink-0 text-[#777777]" />
        <span class="sr-only">{{ $placeholder }}</span>
        <input type="search" x-ref="searchInput" x-init="hasValue = !!$el.value"
            x-on:input="hasValue = !!$el.value" x-effect="hasValue = !!$el.value"
            placeholder="{{ $placeholder }}" {{ $attributes }} />
        <button type="button" x-cloak x-show="hasValue" aria-label="Очистить поиск"
            x-on:click.prevent="$refs.searchInput.value = ''; $refs.searchInput.dispatchEvent(new Event('input', { bubbles: true })); hasValue = false; $refs.searchInput.focus()"
            class="tris-mobile-press grid size-7 shrink-0 place-items-center rounded-full bg-[#EDEDED] text-[#777777]">
            <x-heroicon-o-x-mark class="size-4" />
        </button>
    </label>
</div>

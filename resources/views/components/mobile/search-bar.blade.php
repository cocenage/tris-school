@props(['placeholder' => 'Поиск', 'model' => 'search'])
<div class="min-w-0 flex-1" x-data>
    <div class="tris-mobile-search" x-on:click.self="$refs.searchInput.focus()">
        <x-heroicon-o-magnifying-glass class="size-[18px] shrink-0 text-[#777777]" />
        <input type="search" x-ref="searchInput" aria-label="{{ $placeholder }}"
            placeholder="{{ $placeholder }}" {{ $attributes }} />
        <button type="button" x-cloak x-show="!!$wire.$get(@js($model))" aria-label="Очистить поиск"
            x-on:click="$wire.$set(@js($model), ''); $refs.searchInput.focus()"
            class="tris-mobile-press grid size-7 shrink-0 place-items-center rounded-full bg-[#EDEDED] text-[#777777]">
            <x-heroicon-o-x-mark class="size-4" />
        </button>
    </div>
</div>

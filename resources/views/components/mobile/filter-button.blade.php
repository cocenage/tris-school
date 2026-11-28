@props(['count' => 0, 'label' => 'Фильтры'])
<button type="button" aria-label="{{ $label }}" {{ $attributes->class(['tris-mobile-press tris-mobile-filter-button relative grid size-[44px] shrink-0 place-items-center rounded-full bg-white text-[#777777]', 'tris-mobile-filter-selected' => $count > 0]) }}>
    <x-heroicon-o-adjustments-horizontal class="size-[18px]" />
    @if($count)<span class="absolute right-0 top-0 grid size-5 place-items-center rounded-full bg-[#2A1F1A] text-[10px] text-white">{{ $count }}</span>@endif
</button>

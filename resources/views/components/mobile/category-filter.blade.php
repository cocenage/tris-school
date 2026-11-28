@props(['options', 'selected' => [], 'toggleAction' => 'toggleCategory', 'resetAction' => 'resetCategories'])
<div role="group" aria-label="Категории" {{ $attributes->class(['flex flex-wrap gap-2']) }}>
    <button type="button" wire:click="{{ $resetAction }}" aria-pressed="{{ empty($selected) ? 'true' : 'false' }}"
        @class(['tris-mobile-press inline-flex min-h-11 items-center gap-1.5 rounded-full px-4 py-2.5 text-[14px] font-medium leading-5', 'bg-[#2A1F1A] text-white' => empty($selected), 'bg-[#F2F2F2] text-[#555555]' => !empty($selected)])>
        @if(empty($selected))<x-heroicon-o-check class="size-4 shrink-0" />@endif
        Все
    </button>
    @foreach($options as $value => $label)
        @php($active = in_array($value, $selected, true))
        <button type="button" wire:key="category-filter-{{ $value }}" wire:click="{{ $toggleAction }}(@js($value))"
            aria-pressed="{{ $active ? 'true' : 'false' }}"
            @class(['tris-mobile-press inline-flex min-h-11 items-center gap-1.5 rounded-full px-4 py-2.5 text-[14px] font-medium leading-5', 'bg-[#2A1F1A] text-white' => $active, 'bg-[#F2F2F2] text-[#555555]' => !$active])>
            @if($active)<x-heroicon-o-check class="size-4 shrink-0" />@endif
            {{ $label }}
        </button>
    @endforeach
</div>

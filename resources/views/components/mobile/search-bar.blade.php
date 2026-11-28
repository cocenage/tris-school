@props(['placeholder' => 'Поиск'])
<label class="flex min-w-0 flex-1 items-center gap-3 rounded-full bg-white px-4 py-3">
    <x-heroicon-o-magnifying-glass class="size-5 shrink-0 text-[#777777]" />
    <span class="sr-only">{{ $placeholder }}</span>
    <input type="search" placeholder="{{ $placeholder }}" {{ $attributes->class(['min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-[#777777] focus-visible:ring-2 focus-visible:ring-[#A0A0A0] rounded-sm']) }} />
</label>

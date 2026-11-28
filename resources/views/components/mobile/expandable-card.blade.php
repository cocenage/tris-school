@props(['title', 'subtitle' => null, 'status', 'tone' => 'neutral', 'date' => null])
<details {{ $attributes->class(['group rounded-[24px] bg-white p-4 text-[#171717]']) }}>
    <summary class="flex cursor-pointer list-none items-start gap-3 [&::-webkit-details-marker]:hidden">
        @isset($icon)<div class="mt-0.5 shrink-0">{{ $icon }}</div>@endisset
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <span class="min-w-0 break-words text-base font-medium leading-snug">{{ $title }}</span>
                <x-heroicon-o-chevron-down class="mt-0.5 size-4 shrink-0 text-[#888888] transition-transform group-open:rotate-180" />
            </div>
            @if(filled($subtitle))<div class="mt-1 break-words text-sm leading-snug text-[#737373]">{{ $subtitle }}</div>@endif
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-[#DEDEDE] pt-2">
                <x-mobile.status-chip :label="$status" :tone="$tone" />
                @if($date)<span class="text-xs text-[#737373]">{{ $date }}</span>@endif
            </div>
        </div>
    </summary>
    <div class="mt-3 space-y-3 border-t border-[#E5E5E5] pt-3">{{ $slot }}</div>
</details>

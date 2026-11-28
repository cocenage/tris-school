@props(['title', 'subtitle' => null, 'status', 'tone' => 'neutral', 'date' => null])
<article x-data="{ expanded: false }" x-id="['request-panel']"
    {{ $attributes->class(['tris-mobile-card-press rounded-[24px] bg-white px-4 pt-4 pb-[14px] text-[#171717]']) }}>
    <button type="button" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()"
        x-bind:aria-controls="$id('request-panel')"
        class="tris-mobile-card-trigger flex w-full items-start gap-4 text-left">
        @isset($icon)<span class="shrink-0">{{ $icon }}</span>@endisset
        <span class="block min-w-0 flex-1">
            <span class="flex items-start justify-between gap-2">
                <span class="min-w-0 break-words text-[16px] font-semibold leading-[22px]">{{ $title }}</span>
                <x-heroicon-o-chevron-down class="mt-0.5 size-4 shrink-0 text-[#999999] transition-transform duration-300 motion-reduce:transition-none"
                    x-bind:class="expanded ? 'rotate-180' : ''" />
            </span>
            @if(filled($subtitle))<span class="mt-1 block break-words text-[14px] font-normal leading-5 text-[#777777]">{{ $subtitle }}</span>@endif
            <span class="mt-1 flex flex-wrap items-center justify-between gap-2 border-t border-dashed border-[#DADADA] pt-[5px]">
                <x-mobile.status-chip :label="$status" :tone="$tone" />
                @if($date)<span class="text-[12px] font-normal leading-4 text-[#777777]">{{ $date }}</span>@endif
            </span>
        </span>
    </button>
    <div class="tris-mobile-card-panel" data-expanded="false" x-bind:data-expanded="expanded.toString()"
        x-bind:id="$id('request-panel')" x-bind:aria-hidden="(!expanded).toString()" x-bind:inert="!expanded">
        <div>
            <div class="mt-3 space-y-3 border-t border-[#EAEAEA] pt-3">{{ $slot }}</div>
        </div>
    </div>
</article>

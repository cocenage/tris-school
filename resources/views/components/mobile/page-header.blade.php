@props(['title', 'subtitle' => null])
<div {{ $attributes->class(['flex flex-wrap items-baseline gap-x-3 gap-y-1']) }}>
    <h1 class="text-[32px] font-semibold leading-[1.2] tracking-[-0.045em] text-[#2A1F1A]">{{ $title }}</h1>
    @if(filled($subtitle))<span class="text-[14px] font-medium text-[#777777]">{{ $subtitle }}</span>@endif
</div>

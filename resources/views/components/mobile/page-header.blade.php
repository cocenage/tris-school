@props(['title', 'subtitle' => null])
<div {{ $attributes->class(['flex flex-wrap items-baseline gap-x-3 gap-y-1']) }}>
    <h1 class="text-[32px] font-semibold leading-tight tracking-[-0.045em] text-[#2A1F1A]">{{ $title }}</h1>
    @if(filled($subtitle))<span class="text-sm text-[#737373]">{{ $subtitle }}</span>@endif
</div>

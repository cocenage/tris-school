@props(['title', 'description' => null])
<div {{ $attributes->class(['flex min-h-[280px] flex-col items-center justify-center gap-2 text-center']) }}>
    <x-heroicon-o-inbox class="mb-2 size-9 text-[#A0A0A0]" />
    <h2 class="font-medium">{{ $title }}</h2>
    @if(filled($description))<div class="max-w-[260px] text-sm text-[#737373]">{{ $description }}</div>@endif
    {{ $slot }}
</div>

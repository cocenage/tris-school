@props(['title', 'description' => null])
<section {{ $attributes->class(['flex min-h-[220px] flex-col items-center justify-center gap-[6px] px-5 py-12 text-center']) }}>
    <h2 class="text-[20px] font-semibold leading-[1.3] tracking-[-0.035em] text-[#2A1F1A]">{{ $title }}</h2>
    @if(filled($description))<div class="max-w-[250px] text-[14px] leading-[1.6] text-[#777777]">{{ $description }}</div>@endif
    @if($slot->isNotEmpty())<div class="mt-3">{{ $slot }}</div>@endif
</section>

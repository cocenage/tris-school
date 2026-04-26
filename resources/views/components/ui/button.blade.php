@props([
    'type' => 'button',
    'variant' => 'primary',
    'href' => null,
    'progress' => 100,
])

@php
    $tag = $href ? 'a' : 'button';

    $safeProgress = max(0, min(100, (int) $progress));
    $isReady = $safeProgress >= 100;

$base = '
    group relative overflow-hidden
    w-full h-[45px]
    rounded-full
    inline-flex items-center justify-center
    px-5
    text-[15px] font-medium
    transition-all duration-300
    active:scale-[0.985]
    disabled:opacity-40 disabled:pointer-events-none
';

    $variants = [
        'primary' => '
    ring-1 ring-transparent
    hover:ring-white/20
',

 'secondary' => '
    text-[#213259]
    border border-[#D9E3EE]
',
    ];

    $classes = trim(
        $base . ' ' . ($variants[$variant] ?? $variants['primary'])
    );

    $textColor = $safeProgress >= 50
        ? '#ffffff'
        : '#213259';
@endphp

<{{ $tag }}
    @if($href)
        href="{{ $href }}"
    @else
        type="{{ $type }}"
        @disabled($disabled ?? false)
    @endif
    {{ $attributes->merge([
        'class' => $classes
    ]) }}
>

    @if($variant === 'primary')
        {{-- спокойный базовый фон --}}
        <span
            class="absolute inset-0 rounded-full bg-[#E7EEF6]"
        ></span>

        {{-- progress fill --}}
<span
    class="absolute inset-y-0 left-0 rounded-full transition-all duration-500 ease-out"
    style="
        width: {{ $safeProgress }}%;
        background:
            radial-gradient(circle at 18% 35%, #5BBEFF 0%, transparent 28%),
            radial-gradient(circle at 72% 28%, #368DC4 0%, transparent 26%),
            radial-gradient(circle at 45% 82%, #2D6494 0%, transparent 30%),
            linear-gradient(
                135deg,
                #213259 0%,
                #2D6494 45%,
                #5BBEFF 100%
            );
        background-size:
            220% 220%,
            260% 260%,
            240% 240%,
            180% 180%;
        filter: saturate(1);
        animation: {{ $isReady ? 'gradientChaos 8s ease-in-out infinite' : 'none' }};
    "
    onmouseenter="this.style.filter='saturate(1.12) brightness(1.03)'"
    onmouseleave="this.style.filter='saturate(1) brightness(1)'"
></span>
    @endif

@if($variant === 'secondary')
    <span
        class="absolute inset-0 rounded-full bg-white transition-all duration-300 group-hover:bg-[#F4F7FB]"
    ></span>

    <span
        class="absolute inset-0 rounded-full border border-transparent transition-all duration-300 group-hover:border-[#C8D8E8]"
    ></span>
@endif

    <span
        class="relative z-10 transition-all duration-300"
        @if($variant === 'primary')
            style="color: {{ $textColor }};"
        @endif
    >
        {{ $slot }}
    </span>

</{{ $tag }}>
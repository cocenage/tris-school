@props([
    'type' => 'button',
    'variant' => 'primary', // primary, secondary
])

@php
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
            text-white
            shadow-[0_10px_30px_rgba(45,100,148,0.28)]
        ',

        'secondary' => '
            text-[#7A7A7A]
            border border-[#D9E3EE]

        ',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge([
        'class' => $base . ' ' . $variants[$variant]
    ]) }}
>
    @if($variant === 'primary')
        <span
            class="absolute inset-0 rounded-full transition-all duration-700 ease-in-out"
            style="
                background: linear-gradient(
                    115deg,
                    #213259 0%,
                    #2D6494 35%,
                    #368DC4 65%,
                    #5BBEFF 100%
                );
                background-size: 200% 100%;
                background-position: 0% 50%;
            "
            onmouseenter="this.style.backgroundPosition='100% 50%'"
            onmouseleave="this.style.backgroundPosition='0% 50%'"
        ></span>
    @endif

    @if($variant === 'secondary')
        <span
            class="absolute inset-0 rounded-full transition-all duration-700 ease-in-out"
            style="
                background: linear-gradient(
                    115deg,
                    #FFFFFF 0%,
                    #F3F7FB 35%,
                    #E7EEF6 65%,
                    #DDE7F2 100%
                );
                background-size: 200% 100%;
                background-position: 0% 50%;
            "
            onmouseenter="this.style.backgroundPosition='100% 50%'"
            onmouseleave="this.style.backgroundPosition='0% 50%'"
        ></span>
    @endif

    <span class="relative z-10">
        {{ $slot }}
    </span>
</button>
@props(['type'])
@php
    $accent = match ($type) {
        'inventory' => '#B89B76',
        'salary' => '#80AD8D',
        'day_off' => '#E5BC65',
        'vacation' => '#86A7C9',
        'schedule' => '#AD9BCC',
        'feedback' => '#CE9DA9',
        default => '#B5B5B5',
    };
@endphp
<svg {{ $attributes->class(['size-6 shrink-0']) }} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    @switch($type)
        @case('vacation')
            <rect x="3" y="7" width="18" height="13" rx="3" fill="{{ $accent }}"/>
            <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M8 10v7m8-7v7" stroke="#302721" stroke-width="2" stroke-linecap="round"/>
            @break
        @case('inventory')
            <rect x="2" y="3" width="20" height="5" rx="1.5" fill="#302721"/><rect x="3.5" y="9.5" width="17" height="11" rx="2" fill="{{ $accent }}"/><path d="M10 13h4" stroke="#302721" stroke-width="2" stroke-linecap="round"/>
            @break
        @case('salary')
            <circle cx="12" cy="12" r="10" fill="{{ $accent }}"/><path d="M15.5 7.5a5 5 0 1 0 0 9M7 10.5h7M7 13.5h6" stroke="#302721" stroke-width="1.8" stroke-linecap="round"/>
            @break
        @case('day_off')
            <circle cx="12" cy="12" r="4.5" fill="{{ $accent }}"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" stroke="#302721" stroke-width="2" stroke-linecap="round"/>
            @break
        @case('schedule')
            <rect x="2.5" y="5" width="19" height="16" rx="2.5" fill="{{ $accent }}"/><path d="M2.5 8a3 3 0 0 1 3-3h13a3 3 0 0 1 3 3v2h-19V8Z" fill="#302721"/><path d="M7 3v3m10-3v3" stroke="#302721" stroke-width="2" stroke-linecap="round"/>
            @break
        @default
            <path d="M12 2a10 10 0 0 0-10 10v8l5-1a10 10 0 1 0 5-17Z" fill="{{ $accent }}"/><path d="M8 10h7m-7 4h4" stroke="#302721" stroke-width="2" stroke-linecap="round"/>
    @endswitch
</svg>

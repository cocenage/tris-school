@props(['label', 'tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'green' => 'bg-[#E7F5ED] text-[#176C3A]',
        'red' => 'bg-[#FCECEB] text-[#B52A23]',
        'orange' => 'bg-[#FFF0DD] text-[#99520D]',
        'blue' => 'bg-[#EAF0FF] text-[#2457A5]',
        default => 'bg-[#EEEEEE] text-[#686868]',
    };
@endphp
<span {{ $attributes->class(['inline-flex max-w-full items-center rounded-full px-2 py-1 text-xs leading-none', $classes]) }}>{{ $label }}</span>

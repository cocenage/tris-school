@props(['label', 'tone' => 'neutral'])
@php
    $classes = match ($tone) {
        'green' => 'bg-[#EAF7EF] text-[#1F7A45]',
        'red' => 'bg-[#FDEDEC] text-[#C53B32]',
        'orange' => 'bg-[#FFF4E5] text-[#B76A16]',
        'blue' => 'bg-[#EEF4FF] text-[#2457A5]',
        default => 'bg-[#EEEEEE] text-[#686868]',
    };
@endphp
<span {{ $attributes->class(['inline-flex max-w-full items-center min-h-[24px] rounded-full px-2 py-1 text-[12px] font-normal leading-4', $classes]) }}>{{ $label }}</span>

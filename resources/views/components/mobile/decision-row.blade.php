@props(['title', 'status', 'tone' => 'neutral', 'description' => null, 'comment' => null])
@php
    $color = match ($tone) {
        'green' => 'text-[#1F7A45]', 'red' => 'text-[#C53B32]',
        'orange' => 'text-[#B76A16]', 'blue' => 'text-[#2457A5]',
        default => 'text-[#686868]',
    };
@endphp
<div {{ $attributes->class(['py-2 first:pt-0 last:pb-0']) }}>
    <div class="flex items-start justify-between gap-3">
        <span class="min-w-0 break-words text-[14px] font-semibold leading-5">{{ $title }}</span>
        <span class="max-w-[45%] shrink-0 text-right text-xs leading-snug {{ $color }}">{{ $status }}</span>
    </div>
    @if(filled($description))<div class="mt-1 text-[12px] leading-[17px] text-[#777777]">{{ $description }}</div>@endif
    @if(filled($comment))<div class="mt-1 whitespace-pre-wrap break-words text-[12px] leading-[17px] text-[#777777]">{{ $comment }}</div>@endif
</div>

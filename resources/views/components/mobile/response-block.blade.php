@props(['comment', 'date' => null, 'label' => 'Ответ администратора'])
<div {{ $attributes->class(['rounded-[14px] bg-[#F5F5F5] p-3']) }}>
    <div class="text-xs text-[#777777]">{{ $label }}</div>
    <div class="mt-1.5 whitespace-pre-wrap break-words text-[14px] leading-5 text-[#444444]">{{ $comment }}</div>
    @if($date)<div class="mt-2 text-xs text-[#777777]">{{ $date }}</div>@endif
</div>

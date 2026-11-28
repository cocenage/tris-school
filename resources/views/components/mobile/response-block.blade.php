@props(['comment', 'date' => null, 'label' => 'Ответ администратора'])
<div {{ $attributes->class(['rounded-2xl bg-[#F2F2F2] p-3']) }}>
    <div class="text-xs text-[#737373]">{{ $label }}</div>
    <div class="mt-1.5 whitespace-pre-wrap break-words text-sm leading-relaxed text-[#414141]">{{ $comment }}</div>
    @if($date)<div class="mt-2 text-xs text-[#737373]">{{ $date }}</div>@endif
</div>

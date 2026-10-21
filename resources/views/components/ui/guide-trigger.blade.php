@props(['label' => null])

<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-guide', { detail: { reset: true } }))"
    class="group flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#E1E1E1] text-white transition-all duration-300 hover:bg-[#7D7D7D] active:scale-[0.95] {{ $label ? 'max-w-[88px] whitespace-normal px-2 text-center text-[11px] leading-tight' : '' }}"
    aria-label="{{ $label ?: 'Открыть инструкцию' }}"
    title="{{ $label ?: 'Открыть инструкцию' }}"
>
    @if ($label)
        <span>{{ $label }}</span>
    @else
        <x-heroicon-o-question-mark-circle class="h-[20px] w-[20px] stroke-[2.4]" />
    @endif
</button>

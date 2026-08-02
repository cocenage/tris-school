<button
    type="button"
    onclick="window.dispatchEvent(new CustomEvent('open-guide', { detail: { reset: true } }))"
    class="group flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#E1E1E1] text-white transition-all duration-300 hover:bg-[#7D7D7D] active:scale-[0.95]"
    aria-label="Открыть инструкцию"
    title="Открыть инструкцию"
>
    <x-heroicon-o-question-mark-circle class="h-[20px] w-[20px] stroke-[2.4]" />
</button>

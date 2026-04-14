{{-- resources/views/components/ui/modal.blade.php --}}
@props([
    'open' => false,
])

<div
    x-data="{
        open: @js($open),

        init() {
            this.$watch('open', value => {
                document.documentElement.classList.toggle('overflow-hidden', value)
                document.body.classList.toggle('overflow-hidden', value)
            })
        },

        close() {
            this.open = false
        }
    }"
    x-modelable="open"
    {{ $attributes->whereDoesntStartWith('class') }}
>
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[120]"
        @keydown.escape.window="close()"
    >
        <div
            class="absolute inset-0 bg-black/45 backdrop-blur-[6px]"
            x-show="open"
            x-transition:enter="transition duration-300 ease-out"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition duration-200 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="close()"
        ></div>

        <div class="absolute inset-0 overflow-y-auto">
            <div class="min-h-full flex items-center justify-center px-[15px] py-6">
                <div
                    x-show="open"
                    x-transition:enter="transition duration-300 ease-out"
                    x-transition:enter-start="opacity-0 translate-y-4 scale-[0.97]"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    x-transition:leave="transition duration-200 ease-in"
                    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                    x-transition:leave-end="opacity-0 translate-y-2 scale-[0.985]"
                    @click.stop
                    class="relative w-full max-w-[768px] rounded-[28px] bg-white shadow-[0_20px_60px_rgba(0,0,0,0.22)]"
                >
                    <button
                        type="button"
                        @click="close()"
                        class="absolute top-4 right-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-[#F3F6FA] text-[#213259] transition hover:bg-[#E8EEF5]"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M7 7L17 17M17 7L7 17"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </button>

                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
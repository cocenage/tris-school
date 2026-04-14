{{-- resources/views/components/ui/bottom-sheet.blade.php --}}
@props([
    'open' => false,
])

<div
    x-data="{
        open: @js($open),
        translateY: 0,
        startY: 0,
        dragging: false,

        init() {
            this.$watch('open', value => {
                document.documentElement.classList.toggle('overflow-hidden', value)
                document.body.classList.toggle('overflow-hidden', value)
            })
        },

        close() {
            this.open = false
            this.translateY = 0
        },

        begin(e) {
            this.dragging = true
            this.startY = e.touches[0].clientY
        },

        move(e) {
            if (!this.dragging) return

            const diff = e.touches[0].clientY - this.startY

            if (diff > 0) {
                this.translateY = diff
            }
        },

        end() {
            this.dragging = false

            if (this.translateY > 120) {
                this.close()
            } else {
                this.translateY = 0
            }
        }
    }"
    x-modelable="open"
    {{ $attributes->whereDoesntStartWith('class') }}
>
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[130]"
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

        <div class="absolute inset-x-0 bottom-0 flex justify-center px-[15px] pb-[15px] pointer-events-none">
            <div
                x-show="open"
                x-transition:enter="transition duration-300 ease-out"
                x-transition:enter-start="opacity-0 translate-y-full"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition duration-200 ease-in"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-full"
                @click.stop
                @touchstart="begin($event)"
                @touchmove="move($event)"
                @touchend="end()"
                :style="`transform: translateY(${translateY}px)`"
                class="pointer-events-auto relative w-full max-w-[768px] rounded-[28px] bg-white shadow-[0_20px_60px_rgba(0,0,0,0.22)]"
            >
                <div class="flex justify-center pt-3">
                    <div class="h-1.5 w-12 rounded-full bg-[#D6DEE8]"></div>
                </div>

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
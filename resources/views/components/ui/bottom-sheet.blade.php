{{-- resources/views/components/ui/bottom-sheet.blade.php --}}
@props([
    'open' => false,
    'model' => null,
    'locked' => false,
])

<div
    x-data="{
        open: @if($model) @entangle($model).live @else @js($open) @endif,
        locked: @js($locked),

        translateY: 0,
        startY: 0,
        currentY: 0,
        dragging: false,
        shouldDrag: false,

        init() {
            this.$watch('open', value => {
                document.documentElement.classList.toggle('overflow-hidden', value)
                document.body.classList.toggle('overflow-hidden', value)

                const tg = window.Telegram?.WebApp

                if (tg) {
                    tg.expand()

                    if (tg.isVersionAtLeast?.('7.7')) {
                        tg.disableVerticalSwipes?.()
                    }
                }

                if (! value) {
                    this.reset()
                }
            })
        },

        reset() {
            this.translateY = 0
            this.startY = 0
            this.currentY = 0
            this.dragging = false
            this.shouldDrag = false
        },

        close() {
            if (this.locked) {
                return
            }

            this.open = false
            this.reset()
        },

        begin(e) {
            if (this.locked) {
                return
            }

            this.dragging = true
            this.shouldDrag = false
            this.startY = e.touches[0].clientY
            this.currentY = this.startY
        },

        move(e) {
            if (this.locked) {
                return
            }

            if (! this.dragging) return

            this.currentY = e.touches[0].clientY

            const diff = this.currentY - this.startY

            if (diff > 12) {
                this.shouldDrag = true
            }

            if (! this.shouldDrag) {
                return
            }

            if (diff > 0) {
                e.preventDefault()
                e.stopPropagation()

                this.translateY = diff
            }
        },

        end(e) {
            if (this.locked) {
                this.reset()
                return
            }

            if (this.shouldDrag && e) {
                e.preventDefault()
                e.stopPropagation()
            }

            this.dragging = false

            if (this.translateY > 120) {
                this.close()
                return
            }

            this.translateY = 0
            this.shouldDrag = false
        },
    }"
    x-modelable="open"
    {{ $attributes->whereDoesntStartWith('class') }}
>
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[130] overscroll-none"
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
            @touchmove.prevent.stop
        ></div>

        <div
            class="absolute inset-x-0 bottom-0 flex justify-center px-[15px] pb-[15px] pointer-events-none"
        >
            <div
                x-show="open"
                x-transition:enter="transition duration-300 ease-out"
                x-transition:enter-start="opacity-0 translate-y-full"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition duration-200 ease-in"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-full"
                @click.stop
                @touchstart.stop="begin($event)"
                @touchmove.stop="move($event)"
                @touchend.stop="end($event)"
                @touchcancel.stop="end($event)"
                :style="`transform: translateY(${translateY}px)`"
                class="pointer-events-auto relative w-full max-w-[768px] rounded-[28px] bg-white shadow-[0_20px_60px_rgba(0,0,0,0.22)] overscroll-contain"
            >
                <div class="flex justify-center pt-3">
                    <div class="h-1.5 w-12 rounded-full bg-[#D6DEE8]"></div>
                </div>

                {{ $slot }}
            </div>
        </div>
    </div>
</div>
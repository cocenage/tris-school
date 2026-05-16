{{-- resources/views/components/ui/guide.blade.php --}}
@php
    $guideKey = $attributes->get('guide-key', 'default-guide');
@endphp

<div
    x-data="{
        open: false,
        guideKey: @js($guideKey),

        init() {
            if (!localStorage.getItem(this.guideKey)) {
                setTimeout(() => {
                    this.open = true;
                }, 350);
            }

            window.addEventListener('open-guide', () => {
                this.open = true;
            });

            window.addEventListener('close-guide', (event) => {
                this.close(event.detail?.save ?? true);
            });

            this.$watch('open', value => {
                document.documentElement.classList.toggle('overflow-hidden', value);
                document.body.classList.toggle('overflow-hidden', value);
            });
        },

        close(save = true) {
            this.open = false;

            if (save) {
                localStorage.setItem(this.guideKey, '1');
            }
        }
    }"
>
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[130]"
        @keydown.escape.window="close(true)"
        style="display: none;"
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
            @click="close(true)"
        ></div>

        <div class="absolute inset-0 overflow-y-auto">
            <div class="flex min-h-full items-center justify-center px-[15px] py-6">
                <div
                    x-show="open"
                    x-transition:enter="transition duration-300 ease-out"
                    x-transition:enter-start="opacity-0 translate-y-4 scale-[0.97]"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    x-transition:leave="transition duration-200 ease-in"
                    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                    x-transition:leave-end="opacity-0 translate-y-2 scale-[0.985]"
                    @click.stop
                    class="relative w-full max-w-[768px] overflow-hidden rounded-[28px] bg-white shadow-[0_20px_60px_rgba(0,0,0,0.22)]"
                >
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
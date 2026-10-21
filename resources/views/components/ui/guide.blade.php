@props(['steps' => [], 'mode' => 'modal'])

@php
    $guideKey = $attributes->get('guide-key', 'default-guide');
    $guideSeen = false;

    if (auth()->check()) {
        try {
            $guideSeen = \App\Models\UserFormGuide::query()
                ->where('user_id', auth()->id())
                ->where('form_key', $guideKey)
                ->whereNotNull('seen_at')
                ->exists();
        } catch (\Throwable) {
            // The migration may not have been applied yet; keep the guide usable.
        }
    }
@endphp

@if ($mode === 'spotlight')
    <div x-data="{
        open: false,
        seen: @js($guideSeen),
        guideKey: @js($guideKey),
        steps: @js(array_values($steps)),
        current: 0,
        panes: [],
        lastRect: '',
        highlightStyle: '',
        popupStyle: '',
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        init() {
            this.onOpen = () => this.start();
            this.onClose = event => this.close(event.detail?.save ?? true);
            this.onReposition = () => { if (this.open) window.requestAnimationFrame(() => this.measure()); };
            window.addEventListener('open-guide', this.onOpen);
            window.addEventListener('close-guide', this.onClose);
            window.addEventListener('resize', this.onReposition);
            window.addEventListener('scroll', this.onReposition, true);
            this.observer = new MutationObserver(this.onReposition);
            this.observer.observe(document.body, { childList: true, subtree: true });
            this.popupObserver = new ResizeObserver(() => {
                if (this.open) window.requestAnimationFrame(() => this.measure());
            });
            this.$nextTick(() => {
                if (this.$refs.popup) this.popupObserver.observe(this.$refs.popup);
            });
            if (!this.seen) this.autoTimer = window.setTimeout(() => this.start(), 350);
        },
        destroy() {
            window.removeEventListener('open-guide', this.onOpen);
            window.removeEventListener('close-guide', this.onClose);
            window.removeEventListener('resize', this.onReposition);
            window.removeEventListener('scroll', this.onReposition, true);
            window.clearTimeout(this.autoTimer);
            this.observer?.disconnect();
            this.popupObserver?.disconnect();
        },
        start() {
            if (!this.steps.length) return;
            this.current = 0;
            this.open = true;
            this.$nextTick(() => this.focusStep());
        },
        focusStep() {
            this.lastRect = '';
            window.dispatchEvent(new CustomEvent('guide-step-target', { detail: this.steps[this.current].target }));
            const target = document.querySelector('[data-guide-target=' + this.steps[this.current].target + ']');
            if (!target) return;
            this.$nextTick(() => {
                target.scrollIntoView({ behavior: this.reduced ? 'instant' : 'smooth', block: 'center' });
                this.measure();
                window.setTimeout(() => this.measure(), this.reduced ? 0 : 350);
            });
        },
        measure() {
            if (!this.open) return;
            const target = document.querySelector('[data-guide-target=' + this.steps[this.current].target + ']');
            if (!target) return;
            const rect = target.getBoundingClientRect();
            const height = this.$refs.popup?.offsetHeight || 210;
            const signature = [this.current, rect.left, rect.top, rect.right, rect.bottom, window.innerWidth, window.innerHeight, height].join(':');
            if (signature === this.lastRect) return;
            this.lastRect = signature;
            const left = Math.max(0, rect.left - 6);
            const top = Math.max(0, rect.top - 6);
            const right = Math.min(window.innerWidth, rect.right + 6);
            const bottom = Math.min(window.innerHeight, rect.bottom + 6);
            this.panes = [
                { top: 0, left: 0, width: window.innerWidth, height: top },
                { top: bottom, left: 0, width: window.innerWidth, height: window.innerHeight - bottom },
                { top, left: 0, width: left, height: bottom - top },
                { top, left: right, width: window.innerWidth - right, height: bottom - top },
            ];
            this.highlightStyle = `top:${top}px;left:${left}px;width:${right - left}px;height:${bottom - top}px`;
            const width = Math.min(340, window.innerWidth - 32);
            const popupLeft = Math.max(16, Math.min(window.innerWidth - width - 16, (left + right - width) / 2));
            const preferredPopupTop = window.innerHeight - bottom >= top
                ? bottom + 12 : top - height - 12;
            const maxPopupTop = Math.max(16, window.innerHeight - height - 16);
            const popupTop = Math.max(16, Math.min(maxPopupTop, preferredPopupTop));
            this.popupStyle = `width:${width}px;left:${popupLeft}px;top:${popupTop}px`;
        },
        next() {
            if (this.current === this.steps.length - 1) return this.close(true);
            this.current++;
            this.focusStep();
        },
        back() {
            if (this.current === 0) return;
            this.current--;
            this.focusStep();
        },
        close(save = true) {
            this.open = false;
            this.panes = [];
            if (!save || this.seen) return;
            this.seen = true;
            fetch(@js(route('form-guides.seen')), {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify({ guide_key: this.guideKey }),
            }).catch(() => {});
        }
    }" @keydown.escape.window="close(true)">
        <template x-for="(pane, index) in panes" :key="index">
            <div x-show="open" class="pointer-events-none fixed z-[130] bg-black/40 motion-reduce:transition-none" :style="`top:${pane.top}px;left:${pane.left}px;width:${pane.width}px;height:${pane.height}px`"></div>
        </template>
        <div x-show="open" x-cloak class="pointer-events-none fixed z-[131] rounded-[20px] ring-2 ring-[#213259]" :style="highlightStyle"></div>
        <div x-show="open" x-cloak x-ref="popup" :style="popupStyle" class="fixed z-[132] rounded-[20px] bg-white p-4 text-[#213259] shadow-[0_16px_48px_rgba(0,0,0,0.22)]" role="dialog" aria-label="Как заполнить заявку">
            <div class="mb-2 flex items-start justify-between gap-3">
                <h2 class="text-[16px] font-semibold" x-text="steps[current]?.title"></h2>
                <button type="button" @click="close(true)" class="text-[14px] text-[#52627A]" aria-label="Закрыть обучение">Закрыть</button>
            </div>
            <p class="text-[14px] leading-[1.5]" x-text="steps[current]?.text"></p>
            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-[12px] text-[#7D8CA3]" x-text="(current + 1) + ' / ' + steps.length"></span>
                <div class="flex gap-2">
                    <button type="button" x-show="current > 0" @click="back()" class="rounded-full bg-[#F1F4F8] px-4 py-2 text-[14px]">Назад</button>
                    <button type="button" @click="next()" class="rounded-full bg-[#213259] px-4 py-2 text-[14px] text-white" x-text="current === steps.length - 1 ? 'Готово' : 'Далее'"></button>
                </div>
            </div>
        </div>
    </div>
@else
<div
    x-data="{
        open: false,
        seen: @js($guideSeen),
        guideKey: @js($guideKey),
        markSeen() {
            if (this.seen) return;

            this.seen = true;
            fetch(@js(route('form-guides.seen')), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify({ guide_key: this.guideKey }),
            }).catch(() => {});
        },
        init() {
            if (!this.seen) {
                setTimeout(() => { this.open = true; }, 350);
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
                this.markSeen();
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
                    @if (count($steps) > 0)
                        <div
                            x-data="{ current: 0, steps: @js(array_values($steps)) }"
                            class="flex min-h-[80vh] flex-col bg-white"
                        >
                            <div class="flex items-center justify-between px-[20px] pt-[20px]">
                                <div class="flex gap-[6px]">
                                    <template x-for="(_, index) in steps" :key="index">
                                        <div
                                            class="h-[5px] rounded-full transition-all duration-300"
                                            :class="index <= current ? 'w-[26px] bg-[#111111]' : 'w-[5px] bg-[#DADADA]'"
                                        ></div>
                                    </template>
                                </div>

                                <button
                                    type="button"
                                    @click="close(true)"
                                    class="flex h-[42px] w-[42px] items-center justify-center rounded-full bg-[#F4F4F4] text-[#111111]"
                                    aria-label="Закрыть инструкцию"
                                >
                                    <x-heroicon-o-x-mark class="h-[20px] w-[20px] stroke-[2.4]" />
                                </button>
                            </div>

                            <div class="flex flex-1 flex-col px-[20px] pb-[20px] pt-[18px]">
                                <template x-for="(step, index) in steps" :key="index">
                                    <div x-show="current === index" class="flex flex-1 flex-col">
                                        <template x-if="step.image">
                                            <div class="mb-[18px] flex h-[220px] items-center justify-center overflow-hidden rounded-[24px] bg-[#F4F7FB]">
                                                <img :src="step.image" :alt="step.title" class="h-full w-full object-contain">
                                            </div>
                                        </template>

                                        <h2 class="text-[22px] font-semibold tracking-[-0.02em] text-[#111111]" x-text="step.title"></h2>
                                        <p class="pt-[12px] text-[16px] leading-[1.5] text-black/55" x-text="step.text"></p>

                                        <div class="mt-auto flex gap-[10px] pt-[28px]">
                                            <x-ui.button
                                                variant="secondary"
                                                type="button"
                                                x-show="current > 0"
                                                @click="current--"
                                            >
                                                Назад
                                            </x-ui.button>
                                            <x-ui.button
                                                variant="primary"
                                                type="button"
                                                @click="current < steps.length - 1 ? current++ : close(true)"
                                            >
                                                <span x-text="current < steps.length - 1 ? 'Продолжить' : 'Понятно'"></span>
                                            </x-ui.button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    @else
                        {{ $slot }}
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endif

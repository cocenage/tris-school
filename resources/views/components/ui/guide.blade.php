@props(['steps' => []])

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

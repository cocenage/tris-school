{{-- resources/views/components/ui/toast.blade.php --}}
@props([
    'position' => 'top-right',
])

@php
    $positionClasses = match ($position) {
        'bottom-center' => 'bottom-4 left-1/2 -translate-x-1/2',
        'top-center' => 'top-4 left-1/2 -translate-x-1/2',
        default => 'top-4 right-4',
    };
@endphp

<div
    x-data="toastSystem()"
    x-init="init()"
    class="fixed {{ $positionClasses }} z-[9999] w-[calc(100%-30px)] max-w-[430px] pointer-events-none"
>
    <div class="flex flex-col gap-[14px]">
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-show="toast.visible"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-x-[22px] scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-x-0 scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-x-0 scale-100"
                x-transition:leave-end="opacity-0 translate-x-[18px] scale-[0.98]"
                class="pointer-events-auto relative overflow-hidden rounded-[26px] border px-[18px] py-[16px] shadow-[0_18px_45px_rgba(15,23,42,0.12)] backdrop-blur-xl"
                :class="toast.theme.wrapper"
            >
                <div class="flex items-center gap-[16px]">
                    <div class="shrink-0" :class="toast.theme.icon">
                        <template x-if="toast.type === 'success'">
                            <x-heroicon-o-check-circle class="h-[38px] w-[38px] stroke-[1.9]" />
                        </template>

                        <template x-if="toast.type === 'warning'">
                            <x-heroicon-o-exclamation-triangle class="h-[38px] w-[38px] stroke-[1.9]" />
                        </template>

                        <template x-if="toast.type === 'info'">
                            <x-heroicon-o-exclamation-circle class="h-[38px] w-[38px] stroke-[1.9]" />
                        </template>

                        <template x-if="toast.type === 'error'">
                            <x-heroicon-o-exclamation-circle class="h-[38px] w-[38px] stroke-[1.9]" />
                        </template>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[18px] font-semibold leading-[1.15]" :class="toast.theme.title" x-text="toast.title"></div>

                        <div
                            x-show="toast.message"
                            class="mt-[4px] truncate text-[14px] leading-[1.25]"
                            :class="toast.theme.message"
                            x-text="toast.message"
                        ></div>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 rounded-full p-[4px] transition-all duration-300 active:scale-[0.9]"
                        :class="toast.theme.close"
                        @click="remove(toast.id)"
                    >
                        <x-heroicon-o-x-mark class="h-[30px] w-[30px] stroke-[2.3]" />
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
    function toastSystem() {
        return {
            toasts: [],

            themes: {
                success: {
                    wrapper: 'bg-[#E9F8EF]/95 border-[#A8DFC0] shadow-[#A8DFC0]/25',
                    icon: 'text-[#168D4B]',
                    title: 'text-[#168D4B]',
                    message: 'text-[#168D4B]/70',
                    close: 'text-[#168D4B] hover:bg-[#168D4B]/10',
                },
                warning: {
                    wrapper: 'bg-[#FFF4D8]/95 border-[#F0D17A] shadow-[#F0D17A]/25',
                    icon: 'text-[#A98700]',
                    title: 'text-[#A98700]',
                    message: 'text-[#A98700]/70',
                    close: 'text-[#A98700] hover:bg-[#A98700]/10',
                },
                info: {
                    wrapper: 'bg-[#E8F1FF]/95 border-[#9CBFFF] shadow-[#9CBFFF]/25',
                    icon: 'text-[#2D64D8]',
                    title: 'text-[#2D64D8]',
                    message: 'text-[#2D64D8]/70',
                    close: 'text-[#2D64D8] hover:bg-[#2D64D8]/10',
                },
                error: {
                    wrapper: 'bg-[#FDEBE7]/95 border-[#F1A89D] shadow-[#F1A89D]/25',
                    icon: 'text-[#C91616]',
                    title: 'text-[#C91616]',
                    message: 'text-[#C91616]/70',
                    close: 'text-[#C91616] hover:bg-[#C91616]/10',
                },
            },

            init() {
                if (window.__toastSystemRegistered) {
                    return
                }

                window.__toastSystemRegistered = true

                window.addEventListener('toast', (event) => {
                    this.push(event.detail || {})
                })
            },

            push(payload) {
                const id = Date.now() + Math.random()
                const type = payload.type ?? 'info'

                const toast = {
                    id,
                    visible: true,
                    type,
                    title: payload.title ?? 'Уведомление',
                    message: payload.message ?? '',
                    theme: this.themes[type] ?? this.themes.info,
                }

                this.toasts.push(toast)

                setTimeout(() => {
                    this.remove(id)
                }, payload.duration ?? 3500)
            },

            remove(id) {
                const toast = this.toasts.find(item => item.id === id)
                if (!toast) return

                toast.visible = false

                setTimeout(() => {
                    this.toasts = this.toasts.filter(item => item.id !== id)
                }, 220)
            },
        }
    }
</script>
{{-- resources/views/components/ui/toast.blade.php --}}
@props([
    'position' => 'top-center', // top-center, top-right, bottom-center
])

@php
    $positionClasses = match ($position) {
        'top-right' => 'top-4 right-4',
        'bottom-center' => 'bottom-4 left-1/2 -translate-x-1/2',
        default => 'top-4 left-1/2 -translate-x-1/2',
    };
@endphp

<div
    x-data="toastSystem()"
    x-init="init()"
    class="fixed {{ $positionClasses }} z-[200] w-[calc(100%-24px)] max-w-[560px] pointer-events-none"
>
    <div class="space-y-3">
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-show="toast.visible"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-2 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]"
                class="pointer-events-auto relative overflow-hidden rounded-[28px] border px-5 py-4 shadow-[0_12px_30px_rgba(15,23,42,0.10)] backdrop-blur-sm"
                :class="toast.theme.wrapper"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="shrink-0 flex items-center justify-center w-12 h-12 rounded-full border"
                        :class="toast.theme.iconWrap"
                    >
                        <template x-if="toast.type === 'success'">
                            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" :class="toast.theme.icon">
                                <path d="M7 12.5L10.2 15.7L17.5 8.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </template>

                        <template x-if="toast.type === 'warning'">
                            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" :class="toast.theme.icon">
                                <path d="M12 8V13" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                                <circle cx="12" cy="17" r="1" fill="currentColor"/>
                                <path d="M10.29 4.86L3.82 16.95C3.02 18.44 4.1 20.25 5.79 20.25H18.21C19.9 20.25 20.98 18.44 20.18 16.95L13.71 4.86C12.87 3.28 11.13 3.28 10.29 4.86Z" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>
                            </svg>
                        </template>

                        <template x-if="toast.type === 'info'">
                            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" :class="toast.theme.icon">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.2"/>
                                <path d="M12 10.5V16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                                <circle cx="12" cy="7.5" r="1" fill="currentColor"/>
                            </svg>
                        </template>

                        <template x-if="toast.type === 'error'">
                            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" :class="toast.theme.icon">
                                <path d="M12 8V13" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                                <circle cx="12" cy="17" r="1" fill="currentColor"/>
                                <path d="M8.1 4.5H15.9L19.5 8.1V15.9L15.9 19.5H8.1L4.5 15.9V8.1L8.1 4.5Z" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>
                            </svg>
                        </template>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="text-[18px] leading-5 font-semibold" :class="toast.theme.title" x-text="toast.title"></div>
                        <div
                            class="mt-1 text-[14px] leading-5"
                            :class="toast.theme.message"
                            x-show="toast.message"
                            x-text="toast.message"
                        ></div>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center transition-colors"
                        :class="toast.theme.close"
                        @click="remove(toast.id)"
                    >
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none">
                            <path d="M7 7L17 17M17 7L7 17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
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
                    wrapper: 'bg-[#EAF8EE] border-[#B7E7C1]',
                    iconWrap: 'border-[#7FD197] text-[#2EA15F] bg-[#EAF8EE]',
                    icon: 'text-[#2EA15F]',
                    title: 'text-[#2F9B5E]',
                    message: 'text-[#4AA871]',
                    close: 'text-[#2EA15F] hover:bg-[#DDF3E4]',
                },
                warning: {
                    wrapper: 'bg-[#FFF4D9] border-[#F0DA92]',
                    iconWrap: 'border-[#D3BB58] text-[#998B28] bg-[#FFF4D9]',
                    icon: 'text-[#998B28]',
                    title: 'text-[#988B22]',
                    message: 'text-[#A69742]',
                    close: 'text-[#998B28] hover:bg-[#FBECC2]',
                },
                info: {
                    wrapper: 'bg-[#E8EEFF] border-[#C3D3FF]',
                    iconWrap: 'border-[#8FAAFD] text-[#4A6FE3] bg-[#E8EEFF]',
                    icon: 'text-[#4A6FE3]',
                    title: 'text-[#4A6FE3]',
                    message: 'text-[#6E88E7]',
                    close: 'text-[#4A6FE3] hover:bg-[#DDE6FF]',
                },
                error: {
                    wrapper: 'bg-[#FDEDE6] border-[#F2B8A5]',
                    iconWrap: 'border-[#E47B57] text-[#D52D2D] bg-[#FDEDE6]',
                    icon: 'text-[#D52D2D]',
                    title: 'text-[#D52D2D]',
                    message: 'text-[#D66E56]',
                    close: 'text-[#D52D2D] hover:bg-[#F9E0D7]',
                },
            },

            init() {
                window.addEventListener('toast', (event) => {
                    this.push(event.detail || {})
                })
            },

            push(payload) {
                const id = Date.now() + Math.random()
                const type = payload.type ?? 'info'
                const duration = payload.duration ?? 3500

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
                }, duration)
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
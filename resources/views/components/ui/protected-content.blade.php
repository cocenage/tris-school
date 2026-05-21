@props([
    'message' => 'Контент скрыт',
    'description' => 'Вернитесь в приложение, чтобы продолжить просмотр',
])

<div
    x-data="{
        hidden: false,
        hideTimer: null,

        hide(ms = 700) {
            this.hidden = true

            clearTimeout(this.hideTimer)

            this.hideTimer = setTimeout(() => {
                this.hidden = false
            }, ms)
        },

        forceHide() {
            this.hidden = true
        },

        forceShow() {
            this.hidden = false
        },

        block(e) {
            e.preventDefault()
            this.hide()
        },

        init() {
            document.addEventListener('copy', (e) => this.block(e))
            document.addEventListener('cut', (e) => this.block(e))
            document.addEventListener('contextmenu', (e) => this.block(e))
            document.addEventListener('selectstart', (e) => this.block(e))
            document.addEventListener('dragstart', (e) => this.block(e))

            document.addEventListener('keydown', (e) => {
                const key = e.key.toLowerCase()

                if (
                    (e.ctrlKey || e.metaKey) &&
                    ['c', 'x', 'u', 's', 'a', 'p'].includes(key)
                ) {
                    e.preventDefault()
                    this.hide(1200)
                }

                if (e.key === 'PrintScreen') {
                    e.preventDefault()

                    navigator.clipboard?.writeText('')

                    this.hide(1200)
                }
            })

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.forceHide()
                } else {
                    this.forceShow()
                }
            })

            window.addEventListener('blur', () => {
                this.forceHide()
            })

            window.addEventListener('focus', () => {
                this.forceShow()
            })

            window.addEventListener('beforeprint', () => {
                this.forceHide()
            })

            window.addEventListener('afterprint', () => {
                this.forceShow()
            })

            document.addEventListener('touchstart', (e) => {
                if (e.touches.length > 1) {
                    this.hide(900)
                }
            }, { passive: false })

            document.addEventListener('touchmove', (e) => {
                if (e.touches.length > 1) {
                    e.preventDefault()
                    this.hide(900)
                }
            }, { passive: false })

            document.addEventListener('gesturestart', (e) => {
                e.preventDefault()
                this.hide(900)
            })

            document.addEventListener('gesturechange', (e) => {
                e.preventDefault()
                this.hide(900)
            })

            document.addEventListener('gestureend', (e) => {
                e.preventDefault()
                this.hide(900)
            })

            if (window.Telegram && window.Telegram.WebApp) {
                Telegram.WebApp.onEvent('viewportChanged', () => {
                    this.hide(800)
                })

                Telegram.WebApp.onEvent('popupClosed', () => {
                    this.hide(500)
                })

                Telegram.WebApp.disableVerticalSwipes?.()
                Telegram.WebApp.expand?.()
                Telegram.WebApp.ready?.()
            }
        }
    }"
    class="relative overflow-hidden"
    style="
        user-select: none;
        -webkit-user-select: none;
        -webkit-touch-callout: none;
        -webkit-tap-highlight-color: transparent;
    "
>
    <style>
        .protected-content-area,
        .protected-content-area * {
            user-select: none !important;
            -webkit-user-select: none !important;
            -webkit-touch-callout: none !important;
        }

        .protected-content-area img,
        .protected-content-area video {
            pointer-events: none !important;
            -webkit-user-drag: none !important;
            user-drag: none !important;
        }

        body {
            overscroll-behavior: none;
        }

        @media print {
            body * {
                display: none !important;
                visibility: hidden !important;
            }

            body::before {
                content: "Печать этой страницы недоступна";
                display: flex !important;
                visibility: visible !important;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 40px;
                font-size: 24px;
                font-weight: 700;
                color: #111;
                text-align: center;
            }
        }
    </style>

    <div
        class="protected-content-area transition duration-200"
        :class="hidden
            ? 'opacity-0 blur-[22px] scale-[1.015] pointer-events-none'
            : ''
        "
    >
        {{ $slot }}
    </div>

    <div
        x-show="hidden"
        x-transition.opacity
        class="fixed inset-0 z-[999999] flex items-center justify-center px-[20px]"
        style="
            background: rgba(255,255,255,.9);
            backdrop-filter: blur(26px);
            -webkit-backdrop-filter: blur(26px);
        "
    >
        <div class="w-full max-w-[310px] rounded-[35px] bg-white/95 p-[28px] text-center shadow-2xl">
            <div class="text-[22px] font-semibold leading-tight text-[#111]">
                {{ $message }}
            </div>

            <div class="mt-[10px] text-[15px] leading-[1.3] text-[#777]">
                {{ $description }}
            </div>
        </div>
    </div>
</div>
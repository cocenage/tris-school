<div x-data="{
        protectedMode: true,
        hidden: false,

        hide() {
            this.hidden = true
        },

        show() {
            this.hidden = false
        }
    }" x-init="
        document.addEventListener('visibilitychange', () => {
            hidden = document.hidden
        })

        window.addEventListener('blur', () => hidden = true)
        window.addEventListener('focus', () => hidden = false)

        document.addEventListener('keydown', (e) => {
            const key = e.key.toLowerCase()

            if (
                (e.ctrlKey || e.metaKey) &&
                ['c','x','u','s','a','p'].includes(key)
            ) {
                e.preventDefault()
                hidden = true
            }
        })
    " class="relative overflow-hidden">
    <div class="transition duration-150" :class="hidden ? 'opacity-0 pointer-events-none' : ''">
        {{ $slot }}
    </div>

    <div x-show="hidden" class="fixed inset-0 z-[999999] flex items-center justify-center bg-white">
        <div class="text-center">
            <h1> Контент скрыт</h1>
            <p> Вернитесь в приложение</p>

            < </div>
        </div>
    </div>
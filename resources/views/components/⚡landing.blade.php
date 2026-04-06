<?php

use Livewire\Component;

new class extends Component {
    public function mount()
    {
        if (auth()->check()) {
            $user = auth()->user();

           if ($user->status === 'approved') {
    $this->redirectRoute('page-home', navigate: true);
    return;
}

            if ($user->status === 'pending') {
                $this->redirectRoute('access.pending', navigate: true);
                return;
            }

            if ($user->status === 'rejected') {
                $this->redirectRoute('access.rejected', navigate: true);
                return;
            }
        }
    }
};
?>

<div class="min-h-screen bg-[#f7f7f7] flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[32px] bg-white p-6 shadow-sm border border-[#f0f0f0]">
        <h1 class="text-[28px] leading-[34px] font-semibold text-[#111827] mb-3">
            Вход в приложение
        </h1>

        <p class="text-[15px] leading-6 text-[#6b7280] mb-6">
            Для продолжения откройте приложение через Telegram.
        </p>

        <button id="tg-login-btn" class="w-full h-14 rounded-full bg-[#111827] text-white text-[16px] font-medium">
            Продолжить через Telegram
        </button>

        <pre id="debug" class="mt-4 text-[12px] text-red-500 whitespace-pre-wrap"></pre>
    </div>

    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <script>
        const debug = document.getElementById('debug');

        document.getElementById('tg-login-btn').addEventListener('click', async () => {
            try {
                const tg = window.Telegram?.WebApp;

                debug.textContent =
                    'window.Telegram: ' + !!window.Telegram + '\n' +
                    'tg exists: ' + !!tg + '\n' +
                    'initData length: ' + (tg?.initData?.length || 0);

                if (!tg) {
                    alert('Telegram WebApp не найден');
                    return;
                }

                if (!tg.initData) {
                    alert('initData пустой. Открой страницу именно внутри Telegram Mini App.');
                    return;
                }

                const response = await fetch('/telegram/auth', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        init_data: tg.initData,
                    }),
                });

                const text = await response.text();
                debug.textContent += '\nHTTP: ' + response.status + '\nResponse: ' + text;

                const data = JSON.parse(text);

                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            } catch (e) {
                debug.textContent += '\nError: ' + e.message;
                console.error(e);
            }
        });
    </script>
</div>
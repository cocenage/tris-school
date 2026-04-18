<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="min-h-screen bg-[#f7f7f7] flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[32px] bg-white p-6 shadow-sm border border-[#f0f0f0]">
        <div id="app-loading" class="hidden">
            <h1 class="text-[28px] leading-[34px] font-semibold text-[#111827] mb-3">
                Авторизация
            </h1>

            <p class="text-[15px] leading-6 text-[#6b7280] mb-6">
                Выполняем вход через Telegram...
            </p>

            <div class="w-full h-14 rounded-full bg-[#111827] text-white text-[16px] font-medium flex items-center justify-center">
                Загрузка...
            </div>
        </div>

        <div id="browser-login" class="hidden">
            <h1 class="text-[28px] leading-[34px] font-semibold text-[#111827] mb-3">
                Вход в приложение
            </h1>

            <p class="text-[15px] leading-6 text-[#6b7280] mb-6">
                Войдите через Telegram, чтобы продолжить.
            </p>

            <div class="mb-4 flex justify-center">
                <script async
                    src="https://telegram.org/js/telegram-widget.js?22"
                    data-telegram-login="{{ config('services.telegram.bot_username') }}"
                    data-size="large"
                    data-radius="999"
                    data-auth-url="{{ route('telegram.login.widget') }}"
                    data-request-access="write">
                </script>
            </div>

            <a
                href="https://t.me/{{ config('services.telegram.bot_username') }}"
                target="_blank"
                rel="noopener noreferrer"
                class="w-full h-14 rounded-full bg-[#111827] text-white text-[16px] font-medium flex items-center justify-center"
            >
                Открыть бота
            </a>
        </div>

        <pre id="debug" class="mt-4 text-[12px] text-red-500 whitespace-pre-wrap hidden"></pre>
    </div>

    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <script>
        const appLoading = document.getElementById('app-loading');
        const browserLogin = document.getElementById('browser-login');
        const debug = document.getElementById('debug');

        function showBrowser(message = null) {
            appLoading.classList.add('hidden');
            browserLogin.classList.remove('hidden');

            if (message) {
                debug.classList.remove('hidden');
                debug.textContent = message;
            }
        }

        async function sendWriteAccessGranted() {
            try {
                await fetch('{{ route('telegram.write-access') }}', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        granted: true,
                    }),
                });
            } catch (e) {
                console.error('write access save error', e);
            }
        }

        async function tryRequestWriteAccess(tg) {
            if (!tg || typeof tg.requestWriteAccess !== 'function') {
                return;
            }

            try {
                tg.requestWriteAccess(function (granted) {
                    if (granted) {
                        sendWriteAccessGranted();
                    }
                });
            } catch (e) {
                console.error('requestWriteAccess error', e);
            }
        }

        async function loginViaMiniApp() {
            const tg = window.Telegram?.WebApp;

            if (!tg || !tg.initData || tg.initData.length === 0) {
                showBrowser();
                return;
            }

            appLoading.classList.remove('hidden');

            try {
                tg.ready();

                const response = await fetch('{{ route('telegram.auth') }}', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        init_data: tg.initData,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Ошибка авторизации');
                }

                await tryRequestWriteAccess(tg);

                if (data.redirect) {
                    window.location.replace(data.redirect);
                    return;
                }

                throw new Error('Сервер не вернул redirect.');
            } catch (e) {
                console.error(e);
                showBrowser('Mini App auth error: ' + e.message);
            }
        }

        loginViaMiniApp();
    </script>
</div>
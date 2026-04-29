<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
        <div class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-transparent"></div>

        <span class="flex items-center justify-center text-[18px] leading-none">
            Вход
        </span>

        <div class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-transparent"></div>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div class="p-[20px] pb-[28px]">

                <div id="app-loading" class="hidden">
                    <div class="rounded-[30px] border border-[#E7E7E7] bg-[#F8F8F8] p-[20px]">
                        <div class="mx-auto mb-[20px] flex h-[68px] w-[68px] items-center justify-center rounded-[26px] bg-white border border-[#E7E7E7]">
                            <div class="h-[28px] w-[28px] animate-spin rounded-full border-[3px] border-[#E1E1E1] border-t-[#213259]"></div>
                        </div>

                        <h1 class="text-center text-[26px] font-semibold leading-[1.15] tracking-[-0.02em] text-[#111111]">
                            Авторизация
                        </h1>

                        <p class="mx-auto mt-[14px] max-w-[310px] text-center text-[15px] leading-[1.5] text-black/55">
                            Выполняем вход через Telegram. Обычно это занимает пару секунд.
                        </p>

                        <div class="mt-[22px] rounded-[23px] border border-[#E7E7E7] bg-white px-[18px] py-[14px]">
                            <div class="flex items-center gap-[12px]">
                                <div class="flex h-[40px] w-[40px] shrink-0 items-center justify-center rounded-full bg-[#F4F7FB] text-[#213259]">
                                    <x-heroicon-o-shield-check class="h-[20px] w-[20px] stroke-[2.2]" />
                                </div>

                                <div class="min-w-0">
                                    <div class="text-[15px] font-medium text-[#213259]">
                                        Проверяем данные
                                    </div>

                                    <div class="mt-[2px] text-[14px] leading-[1.35] text-black/45">
                                        Не закрывайте страницу
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="browser-login" class="hidden">
                    <div class="rounded-[30px] border border-[#E7E7E7] bg-[#F8F8F8] p-[20px]">
                        <div class="mb-[22px] flex h-[68px] w-[68px] items-center justify-center rounded-[26px] bg-white border border-[#E7E7E7] text-[#213259]">
                            <x-heroicon-o-paper-airplane class="h-[30px] w-[30px] stroke-[2]" />
                        </div>

                        <h1 class="text-[28px] font-semibold leading-[1.1] tracking-[-0.03em] text-[#111111]">
                            Вход в приложение
                        </h1>

                        <p class="mt-[14px] text-[15px] leading-[1.5] text-black/55">
                            Войдите через Telegram, чтобы продолжить работу в приложении.
                        </p>
                    </div>

                    <div class="mt-[14px] rounded-[30px] border border-[#E7E7E7] bg-white p-[18px]">
                        <div class="mb-[16px]">
                            <h2 class="text-[16px] font-medium text-[#213259]">
                                Авторизация
                            </h2>

                            <p class="mt-[6px] text-[14px] leading-[1.45] text-black/45">
                                Нажмите кнопку Telegram ниже. После входа мы автоматически перенаправим вас дальше.
                            </p>
                        </div>

                        <div class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[18px] py-[16px]">
                            <div class="flex justify-center">
                                <script async
                                    src="https://telegram.org/js/telegram-widget.js?22"
                                    data-telegram-login="{{ config('services.telegram.bot_username') }}"
                                    data-size="large"
                                    data-radius="999"
                                    data-auth-url="{{ route('telegram.login.widget') }}"
                                    data-request-access="write">
                                </script>
                            </div>
                        </div>

                        <div class="mt-[12px]">
                            <x-ui.button
                                variant="secondary"
                                href="https://t.me/{{ config('services.telegram.bot_username') }}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Открыть бота
                            </x-ui.button>
                        </div>
                    </div>

                    <div class="mt-[14px] rounded-[23px] bg-[#F4F7FB] px-[16px] py-[14px]">
                        <div class="flex items-start gap-[10px]">
                            <div class="mt-[1px] flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full bg-white text-[#213259]">
                                <x-heroicon-o-information-circle class="h-[18px] w-[18px] stroke-[2]" />
                            </div>

                            <p class="text-[14px] leading-[1.45] text-black/50">
                                Если вы открыли страницу внутри Telegram, вход может выполниться автоматически.
                            </p>
                        </div>
                    </div>
                </div>

                <pre id="debug" class="mt-[14px] hidden whitespace-pre-wrap rounded-[23px] border border-[#F5C2C2] bg-[#FDF2F2] px-[16px] py-[14px] text-[12px] leading-[1.45] text-[#9B1C1C]"></pre>
            </div>
        </div>
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

                if (typeof tg.expand === 'function') {
                    tg.expand();
                }

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
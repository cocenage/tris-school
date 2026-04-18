<?php

use Livewire\Component;

new class extends Component {
    public bool $hasWriteAccess = false;

    public function mount(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('landing.page', navigate: true);
            return;
        }

        if (auth()->user()->status === 'approved') {
            $this->redirectRoute('page-home', navigate: true);
            return;
        }

        if (auth()->user()->status === 'rejected') {
            $this->redirectRoute('access.rejected', navigate: true);
            return;
        }

        $this->hasWriteAccess = ! is_null(auth()->user()->telegram_write_access_granted_at);
    }
};
?>

<div class="min-h-screen bg-[#f7f7f7] flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[32px] bg-white border border-[#ececec] shadow-sm p-6">
        <div class="flex items-center justify-center w-16 h-16 rounded-full bg-[#f3f4f6] mx-auto mb-5 text-[30px]">
            ⏳
        </div>

        <h1 class="text-center text-[28px] leading-[34px] font-semibold text-[#111827]">
            Заявка отправлена
        </h1>

        <p class="mt-3 text-center text-[15px] leading-6 text-[#6b7280]">
            Аккаунт ожидает подтверждения администратора. Обычно это занимает совсем немного времени.
        </p>

        <div class="mt-6 rounded-[24px] bg-[#f8f8f8] border border-[#eeeeee] p-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-white border border-[#ececec] flex items-center justify-center text-[18px] shrink-0">
                    🤖
                </div>

                <div class="min-w-0">
                    <div class="text-[15px] font-medium text-[#111827]">
                        Telegram бот
                    </div>

                    @if ($hasWriteAccess)
                        <div class="mt-1 text-[14px] leading-5 text-[#16a34a]">
                            Бот уже может отправлять вам уведомления.
                        </div>
                    @else
                        <div class="mt-1 text-[14px] leading-5 text-[#6b7280]">
                            Разрешите боту писать вам, чтобы получать уведомления о статусе заявки.
                        </div>
                    @endif
                </div>
            </div>

            @if (! $hasWriteAccess)
                <button
                    id="telegram-write-access-btn"
                    type="button"
                    class="mt-4 w-full h-12 rounded-full bg-[#111827] text-white text-[15px] font-medium transition hover:opacity-95 active:scale-[0.99]"
                >
                    Разрешить уведомления
                </button>
            @endif

            <a
                href="https://t.me/{{ config('services.telegram.bot_username') }}"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 w-full h-12 rounded-full border border-[#e5e7eb] bg-white text-[#111827] text-[15px] font-medium flex items-center justify-center transition hover:bg-[#f8f8f8]"
            >
                Открыть бота
            </a>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf

            <button
                type="submit"
                class="w-full h-12 rounded-full text-[15px] font-medium text-[#6b7280] bg-[#f3f4f6] hover:bg-[#eceff1] transition"
            >
                Выйти
            </button>
        </form>

        <div
            id="write-access-success"
            class="hidden mt-4 rounded-[20px] border border-[#dcfce7] bg-[#f0fdf4] p-4 text-[14px] leading-5 text-[#166534]"
        >
            Отлично! Теперь бот сможет присылать вам уведомления.
        </div>

        <div
            id="write-access-error"
            class="hidden mt-4 rounded-[20px] border border-[#fecaca] bg-[#fef2f2] p-4 text-[14px] leading-5 text-[#991b1b]"
        >
            Не удалось получить разрешение. Попробуйте открыть страницу внутри Telegram.
        </div>
    </div>

    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <script>
        const writeAccessBtn = document.getElementById('telegram-write-access-btn');
        const successBlock = document.getElementById('write-access-success');
        const errorBlock = document.getElementById('write-access-error');

        if (writeAccessBtn) {
            writeAccessBtn.addEventListener('click', () => {
                const tg = window.Telegram?.WebApp;

                if (!tg || typeof tg.requestWriteAccess !== 'function') {
                    errorBlock.classList.remove('hidden');
                    return;
                }

                tg.requestWriteAccess(async function (granted) {
                    if (!granted) {
                        errorBlock.classList.remove('hidden');
                        return;
                    }

                    try {
                        const response = await fetch('{{ route('telegram.write-access') }}', {
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

                        if (!response.ok) {
                            throw new Error('save failed');
                        }

                        if (writeAccessBtn) {
                            writeAccessBtn.remove();
                        }

                        successBlock.classList.remove('hidden');
                        errorBlock.classList.add('hidden');
                    } catch (e) {
                        console.error(e);
                        errorBlock.classList.remove('hidden');
                    }
                });
            });
        }
    </script>
</div>
<?php

use Livewire\Component;

new class extends Component {
    public function mount(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('landing.page', navigate: true);
            return;
        }

        if (auth()->user()->status !== 'rejected') {
            $this->redirectRoute(match (auth()->user()->status) {
                'approved' => 'page-home',
                'pending' => 'access.pending',
                default => 'landing.page',
            }, navigate: true);
        }
    }
};
?>

<div class="min-h-screen bg-[#f7f7f7] flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[32px] bg-white border border-[#ececec] shadow-sm p-6">
        <div class="flex items-center justify-center w-16 h-16 rounded-full bg-[#fef2f2] mx-auto mb-5 text-[30px]">
            ❌
        </div>

        <h1 class="text-center text-[28px] leading-[34px] font-semibold text-[#111827]">
            Доступ отклонён
        </h1>

        <p class="mt-3 text-center text-[15px] leading-6 text-[#6b7280]">
            Администратор отклонил доступ к приложению. Если это ошибка — свяжитесь с администратором.
        </p>

        <a
            href="https://t.me/{{ config('services.telegram.bot_username') }}"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-6 w-full h-12 rounded-full bg-[#111827] text-white text-[15px] font-medium flex items-center justify-center transition hover:opacity-95 active:scale-[0.99]"
        >
            Открыть бота
        </a>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf

            <button
                type="submit"
                class="w-full h-12 rounded-full text-[15px] font-medium text-[#6b7280] bg-[#f3f4f6] hover:bg-[#eceff1] transition"
            >
                Выйти
            </button>
        </form>
    </div>
</div>
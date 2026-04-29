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

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
        <div class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-transparent"></div>

        <span class="flex items-center justify-center text-[18px] leading-none">
            Доступ
        </span>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button
                type="submit"
                class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
            >
                <x-heroicon-o-arrow-right-on-rectangle class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
            </button>
        </form>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div class="p-[20px] pb-[28px]">

                <div class="rounded-[30px] border border-[#F5C2C2] bg-[#FDF2F2] p-[20px]">
                    <div class="mb-[22px] flex h-[68px] w-[68px] items-center justify-center rounded-[26px] bg-white border border-[#F5C2C2] text-[#9B1C1C]">
                        <x-heroicon-o-x-circle class="h-[32px] w-[32px] stroke-[2]" />
                    </div>

                    <h1 class="text-[28px] font-semibold leading-[1.1] tracking-[-0.03em] text-[#111111]">
                        Доступ отклонён
                    </h1>

                    <p class="mt-[14px] text-[15px] leading-[1.5] text-black/55">
                        Администратор отклонил доступ к приложению. Если это ошибка — свяжитесь с ответственным администратором.
                    </p>
                </div>

                <div class="mt-[14px] rounded-[30px] border border-[#E7E7E7] bg-white p-[18px]">
                    <div class="flex items-start gap-[12px]">
                        <div class="flex h-[44px] w-[44px] shrink-0 items-center justify-center rounded-[18px] bg-[#F4F7FB] text-[#213259]">
                            <x-heroicon-o-paper-airplane class="h-[22px] w-[22px] stroke-[2]" />
                        </div>

                        <div class="min-w-0">
                            <h2 class="text-[16px] font-medium text-[#213259]">
                                Telegram бот
                            </h2>

                            <p class="mt-[6px] text-[14px] leading-[1.45] text-black/50">
                                Можно открыть бота и уточнить причину отказа, если доступ должен быть выдан.
                            </p>
                        </div>
                    </div>

                    <div class="mt-[16px]">
                        <x-ui.button
                            variant="primary"
                            href="https://t.me/{{ config('services.telegram.bot_username') }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Открыть бота
                        </x-ui.button>
                    </div>

                    <form method="POST" action="{{ route('logout') }}" class="mt-[10px]">
                        @csrf

                        <x-ui.button
                            type="submit"
                            variant="secondary"
                        >
                            Выйти
                        </x-ui.button>
                    </form>
                </div>

                <div class="mt-[14px] rounded-[23px] bg-[#F4F7FB] px-[16px] py-[14px]">
                    <div class="flex items-start gap-[10px]">
                        <div class="mt-[1px] flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full bg-white text-[#213259]">
                            <x-heroicon-o-information-circle class="h-[18px] w-[18px] stroke-[2]" />
                        </div>

                        <p class="text-[14px] leading-[1.45] text-black/50">
                            После изменения статуса администратором вы сможете снова войти через Telegram.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
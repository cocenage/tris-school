<?php

use Livewire\Component;

new class extends Component {
    //
};
?>
<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<section
    class="relative min-h-screen bg-[#F5F7FA] px-[15px] py-[15px]"
>
    <div class="mx-auto max-w-[760px]">

        {{-- верхние карточки --}}
        <div class="flex gap-3 overflow-x-auto pb-2 no-scrollbar">
            @foreach ([
                'Система уборки по шагам “Tris service”',
                'Список фото',
                'Инструкция по съёмке видео выхода',
                'Все инструкции',
            ] as $item)
                <div
                    class="min-w-[120px] h-[120px] rounded-[24px] bg-[#E9E9E9] p-4 flex items-end text-[13px] font-medium text-[#1F1F1F] leading-[1.2]"
                >
                    {{ $item }}
                </div>
            @endforeach
        </div>

        {{-- вкладки --}}
        <div class="mt-5 flex items-center gap-3">
            <button
                type="button"
                class="h-[30px] px-4 rounded-full bg-[#4A7BB5] text-white text-[13px] font-medium"
            >
                Клинеры
            </button>

            <button
                type="button"
                class="text-[13px] font-medium text-[#1F1F1F]"
            >
                Супервайзеры 🔒
            </button>
        </div>

        {{-- теги --}}
        <div class="mt-4 flex gap-2 overflow-x-auto pb-2 no-scrollbar">
            @for ($i = 0; $i < 8; $i++)
                <div
                    class="shrink-0 h-[28px] px-4 rounded-full bg-[#E5E5E5] flex items-center text-[12px] font-medium text-[#4B4B4B]"
                >
                    Название темы
                </div>
            @endfor
        </div>

        {{-- контент-заглушка --}}
        <div class="mt-6 rounded-[32px] bg-[#EFEFEF] min-h-[600px] relative overflow-hidden">

            @for ($i = 0; $i < 9; $i++)
                <div
                    class="absolute rounded-full bg-[#D9D9D9]"
                    style="
                        width: 64px;
                        height: 64px;
                        top: {{ 40 + ($i * 70) }}px;
                        left: {{ ($i % 2 === 0) ? 80 + ($i * 10) : 180 + ($i * 12) }}px;
                    "
                ></div>
            @endfor

            <div
                class="absolute rounded-full bg-yellow-300"
                style="
                    width: 64px;
                    height: 64px;
                    top: 280px;
                    left: 180px;
                "
            ></div>
        </div>
    </div>

    {{-- модалка "в разработке" --}}
<div class="absolute inset-0 z-[20] rounded-[32px] overflow-hidden">
    <div class="absolute inset-0 bg-black/45 backdrop-blur-[6px]"></div>

    <div class="absolute inset-0 overflow-y-auto">
        <div class="min-h-full flex items-center justify-center px-[15px] py-6">
            <div
                class="relative w-full max-w-[768px] rounded-[28px] bg-white shadow-[0_20px_60px_rgba(0,0,0,0.22)]"
            >
                <div class="px-6 py-8 md:px-10 md:py-10 text-center">

                    {{-- картинка --}}
                    <div class="mb-6 flex justify-center">
                        <img
                            src="{{ asset('images/process.png') }}"
                            alt="В разработке"
                            class="max-w-[220px] w-full h-auto"
                        >
                    </div>

                    {{-- заголовок --}}
                    <h2 class="text-[26px] font-semibold text-[#1F1F1F]">
                        Страница в разработке
                    </h2>

                    {{-- текст --}}
                    <p class="mt-3 text-[15px] leading-[1.6] text-[#6B7280] max-w-[520px] mx-auto">
                        Совсем скоро здесь появятся обучение,
                        инструкции и полезные материалы для работы
                    </p>

                </div>
            </div>
        </div>
    </div>
</div>
</section>
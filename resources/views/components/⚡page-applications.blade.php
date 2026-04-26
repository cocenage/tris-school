<?php

use Livewire\Component;

new class extends Component {
    //
};
?>
<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<div class="flex flex-col justify-between h-full w-full">
    <h1 class="m-[35px]">Заявки</h1>
    <div class="px-[20px] pb-[20px]">
        <div class="grid grid-cols-3 gap-[10px]">

            {{-- большая --}}
            <a href="{{ route('page-applications.inventory') }}"
                class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] h-full flex items-start">
                <p class="text-[14px] leading-[1.2]">Запрос инвентаря</p>
            </a>

            {{-- квадрат --}}
            <a href="{{ route('page-applications.salary') }}"
                class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                <p class="text-[14px] leading-[1.2]">Вопрос по зарплате</p>
            </a>

            {{-- квадрат --}}
            <a href="{{ route('page-applications.weekend') }}"
                class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                <p class="text-[14px] leading-[1.2]">Запрос выходного</p>
            </a>

            {{-- большая --}}
            <a href="{{ route('page-applications.vacation') }}"
                class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] h-full flex items-start">
                <p class="text-[14px] leading-[1.2]">Заявка на отпуск</p>
            </a>

            {{-- большая --}}
            <a href="{{ route('page-applications.schedule') }}"
                class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] h-full flex items-start">
                <p class="text-[14px] leading-[1.2]">Вопрос по графику работы</p>
            </a>

            {{-- квадрат --}}
            <a href="{{ route('page-applications.feedback') }}"
                class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                <p class="text-[14px] leading-[1.2]">Обратная связь</p>
            </a>

        </div>
    </div>
</div>
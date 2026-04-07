<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="h-full flex flex-col overflow-hidden">
    <div class="">
        <livewire:search.search-bar />
    </div>

    <div class="flex-1 overflow-hidden bg-white rounded-t-[50px]">
        <div class="w-full h-full bg-white rounded-t-[50px] overflow-y-auto">
            <div class="w-full flex pt-[20px] pb-[30px] pl-[20px]">
                <h1>Заявки</h1>
            </div>
            <div class="px-[20px] pb-[20px]">
                <div class="grid grid-cols-3 gap-[10px]">

                    {{-- большая --}}
                    <a href="" class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] min-h-[150px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Запрос инвентаря</p>
                    </a>

                    {{-- квадрат --}}
                    <a href="" class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Вопрос по зарплате</p>
                    </a>

                    {{-- квадрат --}}
                    <a  href="{{ route('page-applications.weekend') }}" class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Запрос выходного</p>
                    </a>

                    {{-- большая --}}
                    <a href="" class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] min-h-[150px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Заявка на отпуск</p>
                    </a>

                    {{-- большая --}}
                    <a href="" class="col-span-2 rounded-[30px] bg-[#E1E1E1] p-[15px] min-h-[150px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Вопрос по графику работы</p>
                    </a>

                    {{-- квадрат --}}
                    <a href="" class="aspect-square rounded-[30px] bg-[#E1E1E1] p-[15px] flex items-start">
                        <p class="text-[14px] leading-[1.2]">Отзывы или предложение</p>
                    </a>

                </div>
            </div>
        </div>
    </div>
</div>
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
    <h1 class="m-[20px]">Проверки</h1>
    <div class="px-[20px] pb-[20px] flex flex-col gap-[10px]">

       <a href="{{ route('page-checks.control') }}">
            <div class="grid grid-cols-3 gap-[10px] bg-[#E1E1E1] rounded-[30px]">

                {{-- большая --}}
                <div class="col-span-2 rounded-[30px] bg-[#E1E1E1] h-full flex items-start">
                    <p class="p-[15px]">Контроль</p>
                </div>

                {{-- квадрат --}}
                <div class="aspect-square rounded-[30px] bg-[#E1E1E1] flex items-start">

                </div>

            </div>
        </a>

        <a href="">
            <div class="grid grid-cols-3 gap-[10px] bg-[#E1E1E1] rounded-[30px]">

                {{-- большая --}}
                <div class="col-span-2 rounded-[30px] bg-[#E1E1E1] h-full flex items-start">
                    <p class="p-[15px]">Коучинг</p>
                </div>

                {{-- квадрат --}}
                <div class="aspect-square rounded-[30px] bg-[#E1E1E1] flex items-start">

                </div>
            </div>
        </a>
    </div>
</div>
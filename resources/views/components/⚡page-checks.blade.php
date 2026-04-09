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
                <h1>Проверки</h1>
            </div>


            <div class="px-[20px] flex flex-col gap-[10px]">
                <div class="">
                    <a href=""></a>
                    <div class="flex bg-[#E1E1E1] rounded-[30px] ">
                        <div class="aspect-square w-full h-full">
                            <p class="p-[15px]">Контроль</p>
                        </div>
                        <div class="aspect-square w-full h-full "></div>
                        <div class="aspect-square w-full h-full ml-[10px]"></div>
                    </div>
                    </a>
                </div>
                <div class="">
                    <a href=""></a>
                    <div class="flex w-[100%-10px] bg-[#E1E1E1] rounded-[30px] ">
                        <div class="aspect-square w-full h-full">
                            <p class="p-[15px]">Коучинг</p>
                        </div>
                        <div class="aspect-square w-full h-full "></div>
                        <div class="aspect-square w-full h-full"></div>
                    </div>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>
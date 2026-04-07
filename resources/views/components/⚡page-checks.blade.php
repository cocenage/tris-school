<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<div class="h-full flex flex-col overflow-hidden">
    <div class="">
        <livewire:search.search-bar />
    </div>

    <div class="flex-1 overflow-hidden bg-white rounded-t-[50px]">
        <div class="w-full h-full bg-white rounded-t-[50px] overflow-y-auto">
      <div class="w-full flex p-[20px]">
        <h1>Проверки</h1>
      </div>
<div
    class="flex flex-col gap-[10px] px-[20px]"
    style="--cell: calc((100vw - 40px - 20px) / 3);"
>
    <div class="w-full rounded-[30px] bg-[#E1E1E1]" style="height: var(--cell);">
        <a href="" class="w-full h-full p-[12px] flex items-start">
            <p>Контроль</p>
        </a>
    </div>

    <div class="w-full rounded-[30px] bg-[#E1E1E1]" style="height: var(--cell);">
        <a href="" class="w-full h-full p-[12px] flex items-start">
            <p>Контроль</p>
        </a>
    </div>

   
</div>
        </div>
    </div>
</div>
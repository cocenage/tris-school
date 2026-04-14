<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div class="flex flex-col h-full min-h-0">
    <div class="flex-1 min-h-0 overflow-y-auto">
        это главная
        

    </div>
     <x-ui.button
            variant="primary"
            wire:click="finishCurrentRange"
        >
            ggggg
        </x-ui.button>
 <x-ui.button
            variant="secondary"
            wire:click="finishCurrentRange"
        >
            ggggg
        </x-ui.button>

        <x-ui.button variant="secondary">
    Сбросить заявку
</x-ui.button>
    <div class="shrink-0 border-t border-[#E5E7EB] bg-white p-4">
        <button class="w-full rounded-[20px] bg-[#213259] py-3 text-white">
            Кнопка
        </button>
    </div>
</div>
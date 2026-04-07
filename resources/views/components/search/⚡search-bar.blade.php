<?php

use Livewire\Component;

new class extends Component
{
    public bool $opened = false;

    public function open(): void
    {
        $this->opened = true;
    }

    public function close(): void
    {
        $this->opened = false;
    }
};
?>

<div
    x-data="{ open: @entangle('opened') }"
    class="w-full"
>
    {{-- кнопка / болванка поиска --}}
    <button
        type="button"
        @click="open = true"
        class="w-full"
    >
        <div class="flex items-center h-[60px] rounded-full px-[20px] m-[15px] bg-[#E1E1E1] gap-[10px]">
            <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2.5] opacity-50 shrink-0" />

            <span class="text-[#7A7A7A] text-[16px]">
                Найти в Tris Academy
            </span>
        </div>
    </button>

    {{-- затемнение --}}
    <div
        x-show="open"
        x-transition.opacity
        @click="open = false"
        class="fixed inset-0 z-[90] bg-black/40"
        style="display: none;"
    ></div>

    {{-- открытый поисковик --}}
    <div
        x-show="open"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-[20px] opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-[20px] opacity-0"
        class="fixed left-1/2 top-[20px] z-[100] w-[calc(100%-30px)] max-w-[738px] -translate-x-1/2"
        style="display: none;"
    >
        <div class="rounded-[32px] bg-white p-[15px] shadow-[0_10px_40px_rgba(0,0,0,0.12)]">
            <label
                for="search"
                class="flex items-center h-[60px] rounded-full px-[20px] bg-[#E1E1E1] gap-[10px]"
            >
                <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2.5] opacity-50 shrink-0" />

                <input
                    id="search"
                    type="text"
                    placeholder="Найти в Tris Academy"
                    class="w-full bg-transparent outline-none text-[16px] placeholder:text-[#7A7A7A]"
                    autofocus
                />
            </label>

            <div class="pt-[15px] text-[#7A7A7A] text-[14px]">
                Тут потом будут результаты поиска
            </div>
        </div>
    </div>
</div>
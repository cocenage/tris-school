<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<div class="bg-white w-full h-screen p-[15px]">
    @php
        $user = auth()->user();
    @endphp

    <div class="flex items-center gap-[14px]">
        <div class="w-[64px] h-[64px] rounded-full overflow-hidden bg-[#E1E1E1] shrink-0">
            @if($user?->telegram_photo_url)
                <img
                    src="{{ $user->telegram_photo_url }}"
                    alt="{{ $user->name }}"
                    class="w-full h-full object-cover"
                >
            @else
                <div class="w-full h-full flex items-center justify-center text-[24px] font-semibold text-[#666666]">
                    {{ mb_substr($user->name ?? 'U', 0, 1) }}
                </div>
            @endif
        </div>

        <div class="flex flex-col">
            <span class="text-[20px] font-semibold text-[#111111]">
                {{ $user->name }}
            </span>

            <span class="text-[14px] text-[#777777]">
                @switch($user->role)
                    @case('admin')
                        Администратор
                        @break

                    @case('supervisor')
                        Супервайзер
                        @break

                    @case('cleaner')
                        Клинер
                        @break

                    @default
                        {{ $user->role }}
                @endswitch
            </span>
        </div>
    </div>

    <div class="bg-[#F8F7F5] rounded-[30px] mt-[30px]">
        <div href="" class="flex items-center p-[15px]">
             <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2]" />
             <div class="pl-[15px] w-full flex justify-between items-center">
                <p>Проверка</p>
<x-heroicon-o-magnifying-glass class="w-[15px] h-[15px] stroke-[2]" />
             </div>

        </div>
<hr class="w-full h-[1.5px]">
       <a href="" class="flex items-center p-[15px]">
             <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2]" />
             <div class="pl-[15px] w-full flex justify-between items-center">
                <p>Проверка</p>
<x-heroicon-o-magnifying-glass class="w-[15px] h-[15px] stroke-[2]" />
             </div>

        </a>
    </div>
    <div class="bg-[#F8F7F5] rounded-[30px] mt-[30px] overflow-hidden">
    <a href=""
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <div class="flex items-center gap-[10px]">
                <p class="text-[20px] font-semibold text-[#3B2F2A]">
                    Проверки
                </p>

                <div class="w-[26px] h-[26px] rounded-full bg-[#FF6432] flex items-center justify-center">
                    <span class="text-white text-[12px] font-semibold leading-none">10</span>
                </div>
            </div>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a href=""
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <p class="text-[20px] font-semibold text-[#3B2F2A]">
                Заявки
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a href=""
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <p class="text-[20px] font-semibold text-[#1DFF55]">
                Магазин
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a href="{{ route('page-profile.calendar') }}"
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between py-[8px]">
            <p class="text-[20px] font-semibold text-[#FF6432]">
                Календарь
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

        <a href="{{ route('page-profile.weekend') }}"
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between py-[8px]">
            <p class="text-[20px] font-semibold text-[#FF6432]">
                Выходной деееень
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>
</div>
</div>
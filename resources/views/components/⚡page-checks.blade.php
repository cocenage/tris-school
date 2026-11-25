<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<div class="flex flex-col justify-between h-full w-full">
    <div class="space-y-3 p-5">
        <h1 class="text-xl font-semibold">Контроли</h1>
        <nav class="flex flex-wrap gap-2" aria-label="История контролей">
            <a href="{{ route('page-profile.checks') }}" class="inline-flex min-h-11 items-center rounded-full bg-[#EFF1F3] px-4 text-sm font-semibold text-[#213259]">Мои контроли</a>
            @if(auth()->user()?->canViewAllStaffActivity() && ! app(\App\Services\RolePreviewContext::class)->isActive())
                <a href="{{ route('page-profile.all-checks') }}" class="inline-flex min-h-11 items-center rounded-full bg-[#EFF1F3] px-4 text-sm font-semibold text-[#213259]">Все контроли</a>
            @endif
        </nav>
    </div>
    <div class="px-[20px] pb-[20px] flex flex-col gap-[10px]">

      @if(auth()->user()?->is_active && in_array(auth()->user()?->role, ['admin', 'supervisor'], true))
      <a href="{{ config('focus-v2.enabled') ? route('page-checks.control.focus-v2') : route('page-checks.control.focus') }}">
            <div class="grid grid-cols-3 gap-[10px] bg-[#E1E1E1] rounded-[30px]">

                {{-- большая --}}
                <div class="col-span-2 rounded-[30px] bg-[#E1E1E1] h-full flex items-start">
                    <p class="p-[15px]">Фокус контроль</p>
                    @if(config('focus-v2.enabled'))
                        <p class="px-[15px] pb-[12px] text-xs text-[#425570]">Новая версия</p>
                    @endif
                </div>

                {{-- квадрат --}}
                <div class="aspect-square rounded-[30px] bg-[#E1E1E1] flex items-start">

                </div>

            </div>
        </a>
      @endif

       @if(auth()->user()?->is_active)
       <a href="{{ route('antigoccia.index') }}"
           aria-label="Антикапля — проверить покрытие поверхностей"
           class="group block rounded-[30px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3181BB]">
           <div class="relative flex min-h-[132px] items-center justify-between gap-3 overflow-hidden rounded-[30px] bg-white p-[18px] shadow-[0_4px_20px_rgba(32,56,107,0.06)] transition-transform group-active:scale-[0.99]">
               <div aria-hidden="true" class="absolute -right-8 -top-12 size-[140px] rounded-full bg-[#EFF5FB]"></div>
               <div class="relative min-w-0 flex-1">
                   <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#5F7EA4]">Контроль покрытия</span>
                   <h2 class="mt-1 text-[19px] font-bold tracking-tight text-[#20386B]">Антикапля</h2>
                   <p class="mt-1 text-[12px] leading-5 text-[#718097]">4 поверхности · Состояние после уборки</p>
                   <span class="mt-3 inline-flex items-center gap-1.5 text-[12px] font-bold text-[#2B6DA7]">
                       Открыть анкету
                       <x-heroicon-o-arrow-right class="size-4 transition-transform group-hover:translate-x-1" />
                   </span>
               </div>
               <div class="relative flex size-[88px] shrink-0 items-center justify-center rounded-[26px] bg-gradient-to-br from-[#20386B] to-[#3181BB] text-white shadow-[0_8px_24px_rgba(32,56,107,0.16)]">
                   <x-heroicon-o-sparkles class="size-10" />
               </div>
           </div>
       </a>
       @endif

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

        <a href="{{ route('page-coachings') }}">
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

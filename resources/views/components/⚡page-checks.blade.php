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

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

<div class="w-full space-y-3 p-5">
    <h1 class="text-xl font-semibold">Контроли</h1>

    @if(auth()->user()?->is_active && in_array(auth()->user()?->role, ['admin', 'supervisor'], true))
        <a href="{{ config('focus-v2.enabled') ? route('page-checks.control.focus-v2') : route('page-checks.control.focus') }}"
            class="flex min-h-20 items-center gap-3 rounded-2xl bg-[#E1E1E1] p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3181BB]">
            <x-heroicon-o-clipboard-document-check class="size-7 shrink-0 text-[#425570]" />
            <h2 class="flex-1 text-base font-semibold">Фокус контроль</h2>
            <x-heroicon-o-chevron-right class="size-5 text-[#425570]" />
        </a>
    @endif

    @if(auth()->user()?->is_active)
        <a href="{{ route('antigoccia.index') }}"
            aria-label="Антикапля — проверить покрытие поверхностей"
            class="flex min-h-20 items-center gap-3 rounded-2xl bg-[#E1E1E1] p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3181BB]">
            <x-heroicon-o-beaker class="size-7 shrink-0 text-[#425570]" />
            <div class="flex-1">
                <h2 class="text-base font-semibold">Антикапля</h2>
                <p class="mt-0.5 text-xs text-[#425570]">Контроль покрытия</p>
            </div>
            <x-heroicon-o-chevron-right class="size-5 text-[#425570]" />
        </a>
    @endif
</div>

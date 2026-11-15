<?php

use App\Services\ProfileActivityTimeline;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $zone = 'all';

    public function boot(): void
    {
        abort_unless(auth()->user()?->canViewAllStaffActivity(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'zone'], true)) {
            $this->resetPage();
        }
    }

    public function getItemsProperty(): LengthAwarePaginator
    {
        return app(ProfileActivityTimeline::class)->paginateControls($this->search, $this->zone);
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4">
        <a href="{{ route('page-profile') }}" aria-label="К профилю" class="grid size-11 place-items-center rounded-full bg-[#EFF1F3] text-[#213259]"><x-heroicon-o-arrow-left class="size-5" /></a>
    </div>
</x-slot:header>

<div class="min-h-full bg-[#F5F6F8] px-4 pb-10 pt-6 text-[#182230] sm:px-6">
    <div class="mx-auto max-w-2xl space-y-4">
        @php($items = $this->items)
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-[28px] font-bold leading-tight tracking-tight">Все контроли</h1>
            <span class="text-sm text-[#475467]">Найдено {{ $items->total() }}</span>
        </div>
        <div class="relative">
            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-[#475467]" />
            <input type="search" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Сотрудник, квартира или комментарий" aria-label="Поиск контролей" class="min-h-12 w-full rounded-full border-0 bg-white pl-12 pr-4 text-base placeholder:text-[#596476] focus:ring-2 focus:ring-[#285B86]">
        </div>
        <x-controls.history-tabs active="all" />
        <div class="flex flex-wrap gap-2" aria-label="Итог контроля">
            @foreach(['all' => 'Все итоги', 'green' => 'Зелёная', 'yellow' => 'Жёлтая', 'red' => 'Красная'] as $value => $label)
                <button type="button" wire:click="$set('zone', '{{ $value }}')" aria-pressed="{{ $zone === $value ? 'true' : 'false' }}"
                    class="min-h-10 rounded-full px-3.5 text-sm font-semibold {{ $zone === $value ? 'bg-[#E0EDF8] text-[#214E76]' : 'bg-white text-[#475467]' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="space-y-3">
            @forelse($items as $item)
                <x-controls.result-card :record="$item" wire:key="all-control-{{ $item->id }}" />
            @empty
                <p class="rounded-[26px] bg-white px-4 py-8 text-center text-sm text-[#475467]">Контроли не найдены</p>
            @endforelse
        </div>
        {{ $items->links() }}
        <a href="{{ route('page-profile.staff-history') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-[#285B86]">История сотрудников <span class="ml-2" aria-hidden="true">→</span></a>
    </div>
</div>

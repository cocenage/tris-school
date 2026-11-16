<?php

use App\Models\Coaching;
use App\Services\ProfileActivityTimeline;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'received';

    public string $activityType = 'all';

    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'activityType', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function getItemsProperty(): LengthAwarePaginator
    {
        $timeline = app(ProfileActivityTimeline::class);

        return $this->tab === 'conducted'
            ? $timeline->paginateControls($this->search, supervisorId: (int) Auth::id())
            : $timeline->paginateForCleaner((int) Auth::id(), $this->activityType, search: $this->search);
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
            <h1 class="text-[28px] font-bold leading-tight tracking-tight">Мои контроли</h1>
            <span class="text-sm text-[#475467]">Найдено {{ $items->total() }}</span>
        </div>
        <div class="relative">
            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-[#475467]" />
            <input type="search" wire:model.live.debounce.300ms="search" maxlength="100" placeholder="Сотрудник, квартира или комментарий" aria-label="Поиск в моих контролях" class="min-h-12 w-full rounded-full border-0 bg-white pl-12 pr-4 text-base placeholder:text-[#596476] focus:ring-2 focus:ring-[#285B86]">
        </div>
        @if(auth()->user()?->canViewAllStaffActivity())
            <x-controls.history-tabs active="mine" />
        @endif
        <div class="flex flex-wrap gap-2" aria-label="Участие в контроле">
            @foreach(['received' => 'Меня проверили', 'conducted' => 'Я проверил'] as $value => $label)
                <button type="button" wire:click="$set('tab', '{{ $value }}')" aria-pressed="{{ $tab === $value ? 'true' : 'false' }}"
                    class="min-h-10 rounded-full px-3.5 text-sm font-semibold {{ $tab === $value ? 'bg-[#E0EDF8] text-[#214E76]' : 'bg-white text-[#475467]' }}">{{ $label }}</button>
            @endforeach
        </div>
        @if($tab !== 'conducted')
            <div class="flex flex-wrap gap-2" aria-label="Тип записей">
                @foreach(['all' => 'Всё', 'control' => 'Контроли', 'coaching' => 'Коучинги'] as $value => $label)
                    <button type="button" wire:click="$set('activityType', '{{ $value }}')" aria-pressed="{{ $activityType === $value ? 'true' : 'false' }}"
                        class="min-h-10 rounded-full px-3.5 text-sm font-semibold {{ $activityType === $value ? 'bg-[#E0EDF8] text-[#214E76]' : 'bg-white text-[#475467]' }}">{{ $label }}</button>
                @endforeach
            </div>
        @endif
        <div class="space-y-3">
            @forelse($items as $item)
                @if($item instanceof Coaching)
                    <a wire:key="history-coaching-{{ $item->id }}" href="{{ route('page-coachings.show', $item) }}" class="block rounded-[26px] border border-[#DDE3EC] bg-white p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="font-semibold">Коучинг</h2>
                            <span class="text-xs text-[#475467]">{{ $item->sort_date?->format('d.m.Y') ?? 'Дата не указана' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-[#475467]">{{ $item->supervisor?->name ?? 'Супервайзер не указан' }}</p>
                        <p class="mt-2 line-clamp-2 break-words text-sm">{{ $item->description }}</p>
                    </a>
                @else
                    <x-controls.result-card :record="$item" :direction="$tab" wire:key="my-control-{{ $tab }}-{{ $item->id }}" />
                @endif
            @empty
                <p class="rounded-[26px] bg-white px-4 py-8 text-center text-sm text-[#475467]">Записи не найдены</p>
            @endforelse
        </div>
        {{ $items->links() }}
    </div>
</div>

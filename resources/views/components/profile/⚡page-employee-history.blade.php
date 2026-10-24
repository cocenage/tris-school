<?php

use App\Models\User;
use App\Services\ProfileActivityTimeline;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public User $employee;

    public string $activityType = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(User $employee): void
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
        $this->employee = $employee;
    }

    public function updated($property): void
    {
        if (in_array($property, ['activityType', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function getItemsProperty()
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        return app(ProfileActivityTimeline::class)->paginateForCleaner($this->employee->id, $this->activityType, $this->dateFrom, $this->dateTo);
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]"><a href="{{ route('page-profile.all-checks') }}" aria-label="Назад" class="p-2"><x-heroicon-o-arrow-left class="size-5" /></a><span role="heading" aria-level="1" class="text-[18px] leading-none">История сотрудника</span></div>
</x-slot:header>
<div class="h-full overflow-y-auto bg-white px-5 pb-10 pt-5">
    <div class="mx-auto max-w-2xl space-y-5">
        <h2 class="text-xl font-semibold">{{ $employee->name }}</h2>
        <div class="flex flex-wrap gap-2">@foreach(['all' => 'Всё', 'control' => 'Контроли', 'coaching' => 'Коучинги'] as $value => $label)<button type="button" wire:click="$set('activityType', '{{ $value }}')" aria-pressed="{{ $activityType === $value ? 'true' : 'false' }}" class="rounded-full px-4 py-2 text-sm {{ $activityType === $value ? 'bg-[#213259] text-white' : 'bg-[#F4F7FB] text-[#213259]' }}">{{ $label }}</button>@endforeach</div>
        <div class="grid grid-cols-2 gap-3"><label class="min-w-0 text-sm">С даты<input type="date" wire:model.live="dateFrom" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-black/15 px-2"></label><label class="min-w-0 text-sm">По дату<input type="date" wire:model.live="dateTo" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-black/15 px-2"></label></div>
        @php($items = $this->items)
        @forelse($items as $item)
            @php($isCoaching = $item instanceof \App\Models\Coaching)
            <a wire:key="activity-{{ $item->item_type }}-{{ $item->id }}" href="{{ $isCoaching ? route('page-coachings.show', $item) : route('page-profile.checks.result', $item) }}" class="block rounded-3xl border border-black/10 p-4">
                <div class="flex justify-between gap-3"><span class="font-semibold">{{ $isCoaching ? 'Коучинг' : 'Контроль' }}</span><span class="text-sm text-black/50">{{ $item->sort_date?->format('d.m.Y') ?? 'Дата не указана' }}</span></div>
                <p class="mt-2 text-sm text-black/50">{{ $item->supervisor?->name ?? 'Супервайзер не указан' }}@if($item->apartment) · {{ $item->apartment->name }}@endif</p>
                @if(!$isCoaching)<p class="mt-2 text-sm">{{ $item->result_zone_label }} · {{ (int) $item->total_points }}/{{ (int) $item->max_points }}</p>@endif
                <p class="mt-3 break-words text-sm leading-relaxed">{{ \Illuminate\Support\Str::limit($isCoaching ? ($item->description ?? '') : ($item->comment ?? ''), 180) }}</p>
            </a>
        @empty
            <p class="py-8 text-center text-black/50">Записей пока нет</p>
        @endforelse
        {{ $items->links() }}
    </div>
</div>

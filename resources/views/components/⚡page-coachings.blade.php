<?php

use App\Models\Coaching;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?int $employeeFilter = null;

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'supervisor', 'cleaner'], true), 403);
    }

    public function updated($property): void
    {
        if (in_array($property, ['employeeFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function getCoachingsProperty()
    {
        $user = Auth::user();
        abort_unless(in_array($user?->role, ['admin', 'supervisor', 'cleaner'], true), 403);
        $query = Coaching::query()->with(['cleaner', 'supervisor']);
        if ($user->role === 'supervisor') {
            $query->where('supervisor_id', $user->id);
        } elseif ($user->role === 'cleaner') {
            $query->where('user_id', $user->id);
        } else {
            $query->when($this->employeeFilter, fn ($q) => $q->where('user_id', $this->employeeFilter))
                ->when($this->dateFrom, fn ($q) => $q->whereRaw('date(COALESCE(inspection_date, created_at)) >= ?', [$this->dateFrom]))
                ->when($this->dateTo, fn ($q) => $q->whereRaw('date(COALESCE(inspection_date, created_at)) <= ?', [$this->dateTo]));
        }

        return $query->orderByRaw('COALESCE(inspection_date, created_at) DESC')->orderByDesc('id')->paginate(20);
    }

    public function getEmployeesProperty()
    {
        return Auth::user()?->role === 'admin'
            ? User::query()->where('role', 'cleaner')->orderBy('name')->get(['id', 'name'])
            : collect();
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]">
        <a href="{{ route('page-checks') }}" aria-label="Назад" class="p-2"><x-heroicon-o-arrow-left class="size-5" /></a>
        <span role="heading" aria-level="1" class="text-[18px] leading-none">История коучингов</span>
    </div>
</x-slot:header>

<div class="h-full overflow-y-auto bg-white px-5 pb-10 pt-5">
    <div class="mx-auto max-w-2xl space-y-5">
        @if(in_array(auth()->user()->role, ['admin', 'supervisor'], true))
            <a href="{{ route('page-coachings.create') }}" class="flex min-h-12 items-center justify-center rounded-full bg-[#213259] px-5 font-semibold text-white">Записать коучинг</a>
        @endif
        @if(auth()->user()->role === 'admin')
            <div class="space-y-3">
                <label class="block text-sm">Сотрудник
                    <select wire:model.live="employeeFilter" class="mt-1 min-h-11 w-full rounded-xl border border-black/15 bg-white px-3"><option value="">Все сотрудники</option>@foreach($this->employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="min-w-0 text-sm">С даты<input type="date" wire:model.live="dateFrom" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-black/15 px-2"></label>
                    <label class="min-w-0 text-sm">По дату<input type="date" wire:model.live="dateTo" class="mt-1 min-h-11 w-full min-w-0 rounded-xl border border-black/15 px-2"></label>
                </div>
            </div>
        @endif
        @php($records = $this->coachings)
        @forelse($records as $record)
            <a wire:key="coaching-{{ $record->id }}" href="{{ route('page-coachings.show', $record) }}" class="block rounded-3xl border border-black/10 p-4">
                <div class="flex items-start justify-between gap-3"><span class="font-semibold">{{ $record->cleaner?->name ?? 'Сотрудник недоступен' }}</span><span class="shrink-0 text-sm text-black/50">{{ ($record->conducted_date ?? $record->created_at)?->format('d.m.Y') ?? 'Дата не указана' }}</span></div>
                <p class="mt-1 text-sm text-black/50">{{ $record->supervisor?->name ?? 'Супервайзер не указан' }}</p>
                <p class="mt-3 break-words text-sm leading-relaxed text-black/75">{{ Illuminate\Support\Str::limit($record->description ?? '', 180) }}</p>
            </a>
        @empty
            <p class="py-10 text-center text-black/50">Пока нет коучингов</p>
        @endforelse
        {{ $records->links() }}
    </div>
</div>

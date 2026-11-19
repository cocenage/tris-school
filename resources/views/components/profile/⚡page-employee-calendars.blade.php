<?php

use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public string $workFilter = 'all';

    /** @return Collection<int, User> */
    #[Computed]
    public function employees(): Collection
    {
        Gate::authorize('view-employee-calendars');

        $employees = User::query()->activeStaff()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'role']);

        $today = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'))->startOfDay();
        $calendar = app(EmployeeWorkCalendarService::class)->forEmployees($employees, $today, $today);

        $withTodayStatus = $employees->map(function (User $employee) use ($calendar): User {
            $employee->setAttribute(
                'works_today',
                ($calendar->get($employee->id, collect())->first()['state'] ?? null) === 'planned_work',
            );

            return $employee;
        });

        $filter = in_array($this->workFilter, ['all', 'working', 'not_working'], true)
            ? $this->workFilter
            : 'all';

        return $withTodayStatus->when($filter !== 'all', fn (Collection $items) => $items
            ->filter(fn (User $employee): bool => $filter === 'working'
                ? $employee->works_today
                : ! $employee->works_today))
            ->values();
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]">
        <a href="{{ route('page-profile') }}" aria-label="Назад в профиль" class="p-2">
            <x-heroicon-o-arrow-left class="size-5" />
        </a>
        <h1 class="text-[18px] leading-none">Календари сотрудников</h1>
    </div>
</x-slot:header>

<div class="px-4 pb-8 pt-4">
    <div class="mb-4 space-y-3">
        <label class="block text-sm font-medium text-[#213259]">
            Поиск по сотрудникам
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Введите имя"
                class="mt-1 min-h-11 w-full rounded-2xl border border-black/10 bg-white px-4 text-sm text-[#111] placeholder:text-black/40 focus:border-[#213259] focus:outline-none focus:ring-2 focus:ring-[#213259]/15">
        </label>
        <label class="block text-sm font-medium text-[#213259]">
            Работа сегодня
            <select wire:model.live="workFilter"
                class="mt-1 min-h-11 w-full rounded-2xl border border-black/10 bg-white px-4 text-sm text-[#111] focus:border-[#213259] focus:outline-none focus:ring-2 focus:ring-[#213259]/15">
                <option value="all">Все сотрудники</option>
                <option value="working">Работают сегодня</option>
                <option value="not_working">Не работают сегодня</option>
            </select>
        </label>
    </div>

    <ul class="divide-y divide-black/5 overflow-hidden rounded-[28px] bg-[#F8F7F5]">
        @forelse ($this->employees as $employee)
            <li wire:key="employee-calendar-{{ $employee->id }}">
                <a href="{{ route('employees.calendar', $employee) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-black/5 focus-visible:outline focus-visible:-outline-offset-2">
                    <span class="min-w-0 break-words">
                        <span class="block font-medium">{{ $employee->name }}</span>
                        <span class="block text-sm text-black/60">{{ $employee->role === 'supervisor' ? 'Супервайзер' : 'Клинер' }}</span>
                        <span @class([
                            'mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-[#E7F6EC] text-[#27683C]' => $employee->works_today,
                            'bg-[#ECEEF1] text-[#626873]' => ! $employee->works_today,
                        ])>{{ $employee->works_today ? 'Работает сегодня' : 'Не работает сегодня' }}</span>
                    </span>
                    <x-heroicon-o-chevron-right class="size-4 shrink-0" />
                </a>
            </li>
        @empty
            <li class="px-5 py-4 text-black/60">{{ trim($search) !== '' || $workFilter !== 'all' ? 'Сотрудники по заданным условиям не найдены' : 'Активных сотрудников пока нет' }}</li>
        @endforelse
    </ul>
</div>

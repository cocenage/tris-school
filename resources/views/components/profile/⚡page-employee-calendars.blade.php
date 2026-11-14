<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** @return Collection<int, User> */
    #[Computed]
    public function employees(): Collection
    {
        Gate::authorize('view-employee-calendars');

        return User::query()->activeStaff()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'role']);
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
    <ul class="divide-y divide-black/5 overflow-hidden rounded-[28px] bg-[#F8F7F5]">
        @forelse ($this->employees as $employee)
            <li wire:key="employee-calendar-{{ $employee->id }}">
                <a href="{{ route('employees.calendar', $employee) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-black/5 focus-visible:outline focus-visible:-outline-offset-2">
                    <span class="min-w-0 break-words">
                        <span class="block font-medium">{{ $employee->name }}</span>
                        <span class="block text-sm text-black/60">{{ $employee->role === 'supervisor' ? 'Супервайзер' : 'Клинер' }}</span>
                    </span>
                    <x-heroicon-o-chevron-right class="size-4 shrink-0" />
                </a>
            </li>
        @empty
            <li class="px-5 py-4 text-black/60">Активных сотрудников пока нет</li>
        @endforelse
    </ul>
</div>

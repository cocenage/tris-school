<?php

use App\Models\Instruction;
use App\Services\RolePreviewContext;
use App\Services\Scheduling\CleaningSchedule;
use App\Services\UserApplicationBadgeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function latestInstructions()
    {
        return Instruction::query()
            ->with('category')
            ->where('status', 'published')
            ->where('is_public', true)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function cleaningAssignments(): ?array
    {
        $user = auth()->user();
        if (! $user || app(RolePreviewContext::class)->isActive()) {
            return null;
        }

        return app(CleaningSchedule::class)->today($user);
    }
};
?>

<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<div class="space-y-6 px-4 pb-8 pt-4 sm:px-6">
    @php
        $user = auth()->user();
        $role = app(RolePreviewContext::class);
        $isPreview = $role->isActive();
        $applications = ! $isPreview ? app(UserApplicationBadgeService::class)->label($user->id) : null;
        $canConductControl = in_array($role->effectiveUiRole(), ['admin', 'supervisor'], true);
    @endphp

    <section class="rounded-[30px] bg-[#213259] p-5 text-white sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <h1 class="min-w-0 break-words text-[24px] font-semibold leading-tight">{{ $user->name ?: 'TRIS Academy' }}</h1>
            <a href="{{ route('page-profile') }}" class="flex shrink-0 items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium hover:bg-white/25">
                <x-heroicon-o-user class="size-4" /> Профиль
            </a>
        </div>
        <a href="{{ route($canConductControl ? 'page-checks.control' : 'page-applications') }}" class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-5 font-semibold text-[#213259] hover:bg-[#E7EBF2]">
            <x-heroicon-o-plus class="size-5" />{{ $canConductControl ? 'Начать контроль' : 'Создать заявку' }}
        </a>
    </section>

    @if ($applications)
        <section aria-label="Уведомления">
            <a href="{{ route('page-profile.applications') }}" class="flex items-center gap-3 rounded-3xl bg-[#F4F7FB] p-4 font-medium text-[#213259] hover:bg-[#E7EBF2]">
                <x-heroicon-o-bell class="size-5" />
                <span class="flex-1">Ответы на заявки</span>
                <span class="rounded-full bg-[#213259] px-2.5 py-1 text-xs text-white">{{ $applications }}</span>
            </a>
        </section>
    @endif

    <section aria-labelledby="today-cleanings" class="rounded-[28px] bg-[#F8F7F5] p-4 sm:p-5">
        <h2 id="today-cleanings" class="text-lg font-semibold">Уборки на сегодня</h2>
        @php($assignments = $this->cleaningAssignments)
        @if ($assignments === null)
            <p class="mt-2 text-sm text-black/65">Расписание сейчас недоступно.</p>
        @else
            <ul class="mt-2 divide-y divide-black/10">
                @forelse ($assignments as $assignment)
                    <li class="flex items-baseline gap-4 py-3" wire:key="assignment-{{ $assignment['id'] }}">
                        <span class="font-medium tabular-nums">{{ $assignment['time'] }}</span>
                        @if ($assignment['url'])
                            <a href="{{ $assignment['url'] }}" class="min-w-0 break-words text-[#213259] underline">{{ $assignment['apartment'] }}</a>
                        @else
                            <span class="text-sm text-black/65">Нет доступа к карточке квартиры</span>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-black/65">На сегодня уборок нет.</li>
                @endforelse
            </ul>
        @endif
    </section>

    <nav aria-label="Разделы Академии" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @if (Route::has('learning.index'))
            <a href="{{ route('learning.index') }}" class="flex min-h-16 items-center gap-3 rounded-3xl bg-[#F4F7FB] p-3 font-medium text-[#213259] hover:bg-[#E7EBF2]">
                <x-heroicon-o-academic-cap class="size-5 shrink-0" /> Обучение
            </a>
        @endif
        <a href="{{ route('page-home.instructions') }}" class="flex min-h-16 items-center gap-3 rounded-3xl bg-[#F4F7FB] p-3 font-medium text-[#213259] hover:bg-[#E7EBF2]">
            <x-heroicon-o-book-open class="size-5 shrink-0" /> Инструкции
        </a>
        <a href="{{ route('page-apartments') }}" class="flex min-h-16 items-center gap-3 rounded-3xl bg-[#F4F7FB] p-3 font-medium text-[#213259] hover:bg-[#E7EBF2]">
            <x-heroicon-o-home-modern class="size-5 shrink-0" /> Квартиры
        </a>
    </nav>

    @if ($this->latestInstructions->isNotEmpty())
        <section aria-labelledby="latest-instructions">
            <h2 id="latest-instructions" class="mb-3 text-lg font-semibold">Новые инструкции</h2>
            <ul class="flex gap-3 overflow-x-auto pb-2">
                @foreach ($this->latestInstructions as $instruction)
                    <li class="w-[48vw] max-w-52 shrink-0">
                        <a href="{{ route('page-home.instructions.single', $instruction) }}" class="flex h-full min-h-24 flex-col justify-end rounded-3xl bg-[#F8F7F5] p-4 hover:bg-[#EDEAE6]">
                            @if ($instruction->category)
                                <span class="mb-1 text-xs text-black/60">{{ $instruction->category->title }}</span>
                            @endif
                            <span class="line-clamp-3 font-medium">{{ $instruction->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

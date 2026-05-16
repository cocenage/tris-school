<?php

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskRoom;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function getOverdueTasksProperty()
    {
        return Task::query()
            ->with(['room', 'board', 'column', 'assignees'])
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->orderBy('deadline_at')
            ->limit(3)
            ->get();
    }

    public function getTodayTasksProperty()
    {
        return Task::query()
            ->with(['room', 'board', 'column', 'assignees'])
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereDate('deadline_at', today())
            ->orderBy('deadline_at')
            ->limit(4)
            ->get();
    }

    public function getBoardsProperty()
    {
        $user = Auth::user();

        return TaskBoard::query()
            ->with(['room'])
            ->withCount([
                'tasks as active_tasks_count' => fn ($q) => $q->whereNotIn('status', ['done', 'cancelled']),
                'tasks as overdue_tasks_count' => fn ($q) => $q
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->whereNotNull('deadline_at')
                    ->where('deadline_at', '<', now()),
            ])
            ->where('status', 'active')
            ->whereHas('room', function ($query) use ($user) {
                if ($this->isTaskAdmin($user)) {
                    return;
                }

                $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
            })
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    public function getRoomsCountProperty(): int
    {
        $user = Auth::user();

        return TaskRoom::query()
            ->where('status', 'active')
            ->where(function ($query) use ($user) {
                if ($this->isTaskAdmin($user)) {
                    return;
                }

                $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
            })
            ->count();
    }

    public function getTodayCountProperty(): int
    {
        return Task::query()
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereDate('deadline_at', today())
            ->count();
    }

    public function getOverdueCountProperty(): int
    {
        return Task::query()
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->count();
    }

    public function assigneeNames(Task $task): string
    {
        if ($task->assignees->isNotEmpty()) {
            return $task->assignees->pluck('name')->join(', ');
        }

        return 'Без исполнителя';
    }

    protected function isTaskAdmin($user): bool
    {
        if (method_exists($user, 'canManageTasks')) {
            return $user->canManageTasks();
        }

        return isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true);
    }

    public function taskBackground(Task $task): string
    {
        if ($task->status === 'overdue' || $task->isOverdue()) {
            return '#FFE1E1';
        }

        if ($task->priority === 'urgent') {
            return '#FFE8C7';
        }

        if ($task->status === 'in_progress') {
            return '#E4F0FF';
        }

        return '#F6F6F6';
    }
};
?>

<x-slot:header>
    <div class="flex h-[73px] w-full items-center justify-between px-[15px]">
        <span class="w-[40px]"></span>

        <span class="text-[18px] leading-none">
            Задачи
        </span>

        <a
            href="{{ route('page-tasks.rooms') }}"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#111111] text-white"
        >
            <x-heroicon-o-plus class="h-[20px] w-[20px] stroke-[2.4]" />
        </a>
    </div>
</x-slot:header>

<div class="h-full w-full overflow-hidden">
    <div class="h-full overflow-y-auto rounded-t-[40px] bg-white px-[15px] pt-[18px] pb-[120px]">

        <div class="rounded-[34px] bg-[#111111] p-[18px] text-white">
            <p class="text-[28px] font-semibold leading-none">
                Рабочий день
            </p>

            <p class="mt-[8px] max-w-[270px] text-[14px] leading-[1.25] text-white/60">
                Задачи, доски, дедлайны и рабочий календарь
            </p>

            <div class="mt-[18px] grid grid-cols-2 gap-[8px]">
                <div class="rounded-[24px] bg-white/10 p-[13px]">
                    <p class="text-[12px] text-white/50">Сегодня</p>
                    <p class="mt-[7px] text-[30px] font-semibold leading-none">
                        {{ $this->todayCount }}
                    </p>
                </div>

                <div class="rounded-[24px] bg-white/10 p-[13px]">
                    <p class="text-[12px] text-white/50">Горит</p>
                    <p class="mt-[7px] text-[30px] font-semibold leading-none">
                        {{ $this->overdueCount }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mt-[10px] grid grid-cols-2 gap-[10px]">
            <a
                href="{{ route('page-tasks.calendar') }}"
                class="rounded-[28px] bg-[#E4F0FF] p-[15px]"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[16px] font-medium leading-none">Календарь</p>
                    <x-heroicon-o-calendar-days class="h-[22px] w-[22px]" />
                </div>

                <p class="mt-[8px] text-[12px] leading-[1.2] text-black/45">
                    дедлайны и события
                </p>
            </a>

            <a
                href="{{ route('page-tasks.rooms') }}"
                class="rounded-[28px] bg-[#F6F6F6] p-[15px]"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[16px] font-medium leading-none">Комнаты</p>
                    <x-heroicon-o-folder class="h-[22px] w-[22px]" />
                </div>

                <p class="mt-[8px] text-[12px] leading-[1.2] text-black/45">
                    {{ $this->roomsCount }} активных
                </p>
            </a>
        </div>

        @if ($this->overdueTasks->isNotEmpty())
            <section class="mt-[20px]">
                <div class="mb-[10px] flex items-end justify-between">
                    <div>
                        <p class="text-[22px] font-semibold leading-none">Горит</p>
                        <p class="mt-[5px] text-[13px] text-black/40">Просроченные задачи</p>
                    </div>
                </div>

                <div class="space-y-[8px]">
                    @foreach ($this->overdueTasks as $task)
                        <a
                            href="{{ route('page-tasks.show', $task) }}"
                            class="block rounded-[28px] bg-[#FFE1E1] p-[14px]"
                        >
                            <div class="flex items-start justify-between gap-[12px]">
                                <div class="min-w-0">
                                    <p class="truncate text-[16px] font-semibold leading-none">
                                        {{ $task->title }}
                                    </p>

                                    <p class="mt-[7px] truncate text-[12px] text-black/45">
                                        {{ $task->room?->title }}
                                        @if ($task->deadline_at)
                                            · было до {{ $task->deadline_at->format('d.m H:i') }}
                                        @endif
                                    </p>
                                </div>

                                <span class="shrink-0 rounded-full bg-white/70 px-[9px] py-[5px] text-[12px] leading-none">
                                    срочно
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($this->todayTasks->isNotEmpty())
            <section class="mt-[20px]">
                <div class="mb-[10px]">
                    <p class="text-[22px] font-semibold leading-none">Сегодня</p>
                    <p class="mt-[5px] text-[13px] text-black/40">То, что нужно закрыть сегодня</p>
                </div>

                <div class="space-y-[8px]">
                    @foreach ($this->todayTasks as $task)
                        <a
                            href="{{ route('page-tasks.show', $task) }}"
                            class="block rounded-[28px] p-[14px]"
                            style="background: {{ $this->taskBackground($task) }};"
                        >
                            <div class="flex items-start justify-between gap-[12px]">
                                <div class="min-w-0">
                                    <p class="truncate text-[16px] font-semibold leading-none">
                                        {{ $task->title }}
                                    </p>

                                    <p class="mt-[7px] truncate text-[12px] text-black/45">
                                        {{ $this->assigneeNames($task) }}
                                        @if ($task->deadline_at)
                                            · до {{ $task->deadline_at->format('H:i') }}
                                        @endif
                                    </p>
                                </div>

                                <span class="shrink-0 rounded-full bg-white/70 px-[9px] py-[5px] text-[12px] leading-none">
                                    {{ $task->displayPriority() }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mt-[20px]">
            <div class="mb-[10px] flex items-end justify-between">
                <div>
                    <p class="text-[22px] font-semibold leading-none">Доски</p>
                    <p class="mt-[5px] text-[13px] text-black/40">Продолжить работу</p>
                </div>

                <a href="{{ route('page-tasks.rooms') }}" class="text-[13px] text-black/40">
                    все
                </a>
            </div>

            <div class="space-y-[8px]">
                @forelse ($this->boards as $board)
                    <a
                        href="{{ route('page-tasks.board', $board) }}"
                        class="block rounded-[28px] bg-[#F6F6F6] p-[14px]"
                    >
                        <div class="flex items-start justify-between gap-[12px]">
                            <div class="min-w-0">
                                <p class="truncate text-[17px] font-semibold leading-none">
                                    {{ $board->title }}
                                </p>

                                <p class="mt-[7px] truncate text-[12px] text-black/45">
                                    {{ $board->room?->title }}
                                </p>
                            </div>

                            <x-heroicon-o-chevron-right class="h-[20px] w-[20px] shrink-0 text-black/35" />
                        </div>

                        <div class="mt-[12px] flex flex-wrap gap-[6px]">
                            <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                                {{ $board->active_tasks_count }} активных
                            </span>

                            @if ($board->overdue_tasks_count > 0)
                                <span class="rounded-full bg-[#FFE1E1] px-[9px] py-[5px] text-[12px] leading-none">
                                    {{ $board->overdue_tasks_count }} горит
                                </span>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="rounded-[28px] bg-[#F6F6F6] p-[15px]">
                        <p class="text-[15px] text-black/55">Досок пока нет</p>

                        <x-ui.button href="{{ route('page-tasks.rooms') }}" class="mt-[12px]">
                            Перейти к комнатам
                        </x-ui.button>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
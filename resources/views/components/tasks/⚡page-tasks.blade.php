<?php

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
use App\Models\User;
use App\Services\Tasks\DefaultTaskBoardService;
use App\Services\Tasks\TaskTelegramNotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public ?int $boardFilterId = null;

    public bool $createTaskOpen = false;

    public ?int $boardId = null;
    public ?int $columnId = null;

    public array $assigneeIds = [];

    public ?string $title = '';
    public ?string $description = '';
    public string $priority = 'normal';
    public ?string $deadlineAt = '';

    public function getCanCreateTasksProperty(): bool
    {
        return $this->isTaskAdmin(Auth::user());
    }

public function setDeadlineToday(): void
{
    $this->deadlineAt = now()->setTime(18, 0)->format('Y-m-d\TH:i');
}

public function setDeadlineTomorrow(): void
{
    $this->deadlineAt = now()->addDay()->setTime(18, 0)->format('Y-m-d\TH:i');
}

public function clearDeadline(): void
{
    $this->deadlineAt = '';
}

public function taskBadge(Task $task): string
{
    if (! $task->deadline_at) {
        return 'Без срока';
    }

    if ($task->deadline_at->isPast()) {
        return '🔥 Горит';
    }

    if ($task->deadline_at->isToday()) {
        return 'Сегодня';
    }

    if ($task->deadline_at->isTomorrow()) {
        return 'Завтра';
    }

    return $task->deadline_at->format('d.m');
}

    public function getGreetingTitleProperty(): string
    {
        $name = Auth::user()?->name;

        if (! $name) {
            return 'Привет!';
        }

        $firstName = explode(' ', trim($name))[0];

        return 'Привет, ' . $firstName . '!';
    }

    public function getGreetingTextProperty(): string
    {
        $todayCount = $this->todayTasksCount();
        $todayClosingCount = $this->todayClosingTasksCount();
        $todayStartedCount = $this->todayStartedTasksCount();
        $todayInProgressCount = $this->todayInProgressTasksCount();

        $overdueCount = $this->overdueTasksCount();
        $tomorrowCount = $this->tomorrowTasksCount();

        if ($overdueCount > 0) {
            return 'Горит ' . $overdueCount . ' ' . $this->taskWord($overdueCount) . '. Сначала лучше закрыть их.';
        }

        if ($todayCount > 0) {
            if ($todayClosingCount > 0 && $todayClosingCount === $todayCount) {
                return 'Сегодня нужно закрыть ' . $todayCount . ' ' . $this->taskWordAccusative($todayCount);
            }

            if ($todayStartedCount > 0 && $todayStartedCount === $todayCount) {
                return 'Сегодня начинается ' . $todayCount . ' ' . $this->taskWord($todayCount);
            }

            if ($todayInProgressCount > 0) {
                return 'Сегодня в работе ' . $todayCount . ' ' . $this->taskWord($todayCount);
            }

            return 'У тебя сегодня ' . $todayCount . ' ' . $this->taskWord($todayCount);
        }

        if ($tomorrowCount > 0) {
            return 'Сегодня задач нет, но завтра ' . $tomorrowCount . ' ' . $this->taskWord($tomorrowCount);
        }

        $nextTask = $this->nextTask();

        if ($nextTask?->deadline_at) {
            return 'Сегодня задач нет. Ближайшая — ' . $nextTask->deadline_at->translatedFormat('d F');
        }

        return 'Сегодня задач нет';
    }

    public function getBoardsProperty()
    {
        $user = Auth::user();

        return TaskBoard::query()
            ->with(['room'])
            ->withCount([
                'tasks as active_tasks_count' => fn ($query) => $query
                    ->whereNotIn('status', ['done', 'cancelled']),
            ])
            ->where('status', 'active')
            ->whereHas('room', function ($query) use ($user) {
                if ($this->isTaskAdmin($user)) {
                    return;
                }

                $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
            })
            ->orderByRaw("title = 'Разовые поручения' desc")
            ->orderByDesc('updated_at')
            ->get();
    }

    public function getTasksProperty()
    {
        return Task::query()
            ->with(['room', 'board', 'column', 'assignees', 'assignee', 'checklistItems'])
            ->withCount([
                'comments',
                'checklistItems',
                'checklistItems as done_checklist_items_count' => fn ($query) => $query
                    ->where('is_done', true),
            ])
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->when($this->boardFilterId, function ($query) {
                $query->where('task_board_id', $this->boardFilterId);
            })
            ->orderByRaw("
                CASE
                    WHEN deadline_at IS NOT NULL AND deadline_at < ? THEN 0
                    WHEN deadline_at IS NOT NULL AND DATE(deadline_at) = ? THEN 1
                    WHEN deadline_at IS NOT NULL THEN 2
                    ELSE 3
                END
            ", [
                now(),
                today()->toDateString(),
            ])
            ->orderBy('deadline_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
    }

    public function getCreateBoardsProperty()
    {
        return TaskBoard::query()
            ->with(['room'])
            ->where('status', 'active')
            ->orderByRaw("title = 'Разовые поручения' desc")
            ->orderByDesc('updated_at')
            ->get();
    }

    public function getSelectedBoardProperty(): ?TaskBoard
    {
        if (! $this->boardId) {
            return null;
        }

        return TaskBoard::query()
            ->with(['room.users'])
            ->find($this->boardId);
    }

    public function getColumnsProperty()
    {
        if (! $this->boardId) {
            return collect();
        }

        return TaskBoardColumn::query()
            ->where('task_board_id', $this->boardId)
            ->orderBy('sort_order')
            ->get();
    }

public function getMembersProperty()
{
    if (! $this->selectedBoard || $this->selectedBoard->title === 'Разовые поручения') {
        return User::query()
            ->orderBy('name')
            ->get();
    }

    return $this->selectedBoard
        ->room
        ?->users()
        ->orderBy('name')
        ->get() ?? collect();
}

    public function updatedBoardId(): void
    {
        $this->columnId = $this->columns->first()?->id;
        $this->assigneeIds = [];
    }

    public function openCreateTask(DefaultTaskBoardService $defaultBoard): void
    {
        abort_unless($this->canCreateTasks, 403);

        if (! $this->boardId) {
            $board = $defaultBoard->board();
            $column = $defaultBoard->defaultColumn();

            $this->boardId = $board->id;
            $this->columnId = $column->id;
        }

        $this->createTaskOpen = true;
    }

    public function closeCreateTask(): void
    {
        $this->createTaskOpen = false;

        $this->title = '';
        $this->description = '';
        $this->deadlineAt = '';
        $this->priority = 'normal';
        $this->assigneeIds = [];

        $this->resetValidation();
    }

    public function createTask(
        TaskTelegramNotificationService $telegram,
        DefaultTaskBoardService $defaultBoard,
    ): void {
        abort_unless($this->canCreateTasks, 403);

        $this->validate([
            'boardId' => ['nullable', 'exists:task_boards,id'],
            'columnId' => ['nullable', 'exists:task_board_columns,id'],
            'assigneeIds' => ['array'],
            'assigneeIds.*' => ['exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'deadlineAt' => ['nullable', 'date'],
        ], [
            'title.required' => 'Напиши название задачи.',
            'title.max' => 'Название слишком длинное.',
            'description.max' => 'Описание слишком длинное.',
        ]);

        if ($this->boardId) {
            $board = TaskBoard::query()
                ->with(['room'])
                ->findOrFail($this->boardId);

            $column = $this->columnId
                ? TaskBoardColumn::query()
                    ->where('task_board_id', $board->id)
                    ->findOrFail($this->columnId)
                : $board->columns()->orderBy('sort_order')->first();
        } else {
            $board = $defaultBoard->board();
            $column = $defaultBoard->defaultColumn();
        }

        if (! $column) {
            $board->createDefaultColumns();

            $column = $board->columns()
                ->orderBy('sort_order')
                ->firstOrFail();
        }

        foreach ($this->assigneeIds as $userId) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            $board->room->users()->syncWithoutDetaching([
                $user->id => ['role' => 'member'],
            ]);
        }

        $task = Task::create([
            'task_room_id' => $board->task_room_id,
            'task_board_id' => $board->id,
            'task_board_column_id' => $column->id,
            'created_by' => Auth::id(),
            'assigned_to' => $this->assigneeIds[0] ?? null,
            'title' => trim((string) $this->title),
            'description' => trim((string) $this->description) !== ''
                ? trim((string) $this->description)
                : null,
            'status' => $column->slug ?: 'new',
            'priority' => $this->priority,
            'deadline_at' => $this->deadlineAt ?: null,
            'sort_order' => Task::query()
                ->where('task_board_column_id', $column->id)
                ->max('sort_order') + 10,
        ]);

        $task->assignees()->sync($this->assigneeIds);

        $telegram->notifyTaskAssignees(
            $task->load(['assignees', 'assignee', 'room', 'board', 'column'])
        );

        $this->title = '';
        $this->description = '';
        $this->deadlineAt = '';
        $this->priority = 'normal';
        $this->assigneeIds = [];
        $this->createTaskOpen = false;

        $this->redirectRoute('page-tasks.show', $task, navigate: true);
    }

    protected function baseActiveTasksQuery()
    {
        return Task::query()
            ->visibleFor(Auth::user())
            ->whereNotIn('status', ['done', 'cancelled']);
    }

    protected function activeOnDateQuery($date)
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return $this->baseActiveTasksQuery()
            ->where(function ($query) use ($startOfDay, $endOfDay) {
                $query
                    ->where(function ($query) use ($startOfDay, $endOfDay) {
                        $query
                            ->whereNotNull('starts_at')
                            ->whereNotNull('deadline_at')
                            ->where('starts_at', '<=', $endOfDay)
                            ->where('deadline_at', '>=', $startOfDay);
                    })
                    ->orWhere(function ($query) use ($startOfDay, $endOfDay) {
                        $query
                            ->whereNull('starts_at')
                            ->whereNotNull('deadline_at')
                            ->whereBetween('deadline_at', [$startOfDay, $endOfDay]);
                    })
                    ->orWhere(function ($query) use ($startOfDay, $endOfDay) {
                        $query
                            ->whereNotNull('starts_at')
                            ->whereNull('deadline_at')
                            ->whereBetween('starts_at', [$startOfDay, $endOfDay]);
                    });
            });
    }

    protected function todayTasksCount(): int
    {
        return $this->activeOnDateQuery(today())->count();
    }

    protected function tomorrowTasksCount(): int
    {
        return $this->activeOnDateQuery(today()->addDay())->count();
    }

    protected function overdueTasksCount(): int
    {
        return $this->baseActiveTasksQuery()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->count();
    }

    protected function todayClosingTasksCount(): int
    {
        return $this->baseActiveTasksQuery()
            ->whereDate('deadline_at', today())
            ->count();
    }

    protected function todayStartedTasksCount(): int
    {
        return $this->baseActiveTasksQuery()
            ->whereDate('starts_at', today())
            ->whereNotNull('deadline_at')
            ->whereDate('deadline_at', '>', today())
            ->count();
    }

    protected function todayInProgressTasksCount(): int
    {
        return $this->baseActiveTasksQuery()
            ->whereNotNull('starts_at')
            ->whereNotNull('deadline_at')
            ->whereDate('starts_at', '<', today())
            ->whereDate('deadline_at', '>', today())
            ->count();
    }

    protected function nextTask(): ?Task
    {
        return $this->baseActiveTasksQuery()
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '>', now())
            ->orderBy('deadline_at')
            ->first();
    }

    protected function taskWord(int $count): string
    {
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'задач';
        }

        return match ($count % 10) {
            1 => 'задача',
            2, 3, 4 => 'задачи',
            default => 'задач',
        };
    }

    protected function taskWordAccusative(int $count): string
    {
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'задач';
        }

        return match ($count % 10) {
            1 => 'задачу',
            2, 3, 4 => 'задачи',
            default => 'задач',
        };
    }

    public function taskBackground(Task $task): string
    {
        if (! $task->deadline_at) {
            return '#F1F1F1';
        }

        if ($task->deadline_at->isPast()) {
            return '#F27B7B';
        }

        if ($task->deadline_at->isToday()) {
            return '#F7A6A6';
        }

        if ($task->deadline_at->isTomorrow()) {
            return '#F6CACA';
        }

        return '#F1F1F1';
    }

    public function taskTextColor(Task $task): string
    {
        return '#111111';
    }

    protected function isTaskAdmin($user): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'canManageTasks')) {
            return $user->canManageTasks();
        }

        return isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true);
    }
};
?>

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
   <button
    type="button"
    onclick="history.back()"
    class="group flex h-[40px] min-w-[40px] items-center justify-center rounded-full cursor-pointer bg-[#E1E1E1] text-white backdrop-blur-md transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#7D7D7D] hover:scale-[1.04] active:scale-[0.92]"
>
    <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4] transition-transform duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.08]" />
</button>

        <span class="text-[18px] leading-none flex items-center justify-center">
            Задачи
        </span>


     <button
            type="button"
     
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>


    </div>
</x-slot:header>

<div
    x-data
    x-on:open-create-task.window="$wire.call('openCreateTask')"
    class="flex h-full min-h-0 flex-col bg-white"
>
    <div class="min-h-0 flex-1 overflow-y-auto rounded-t-[40px] bg-white p-[20px]">

        <div>
             <h1 class="">   {{ $this->greetingTitle }} </h1>
             <h2> {{ $this->greetingText }}</h2>
         
      
            </p>
        </div>

        @if ($this->boards->isNotEmpty())
            <div class="mt-[22px] flex gap-[7px] overflow-x-auto no-scrollbar">
                <button
                    type="button"
                    wire:click="$set('boardFilterId', null)"
                    class="shrink-0 rounded-full px-[12px] py-[7px] text-[13px]"
                    style="background: {{ $boardFilterId === null ? '#111111' : '#F2F2F2' }}; color: {{ $boardFilterId === null ? '#FFFFFF' : '#111111' }};"
                >
                    Все
                </button>

                @foreach ($this->boards as $board)
                    <button
                        type="button"
                        wire:click="$set('boardFilterId', {{ $board->id }})"
                        class="shrink-0 rounded-full px-[12px] py-[7px] text-[13px]"
                        style="background: {{ $boardFilterId === $board->id ? '#111111' : '#F2F2F2' }}; color: {{ $boardFilterId === $board->id ? '#FFFFFF' : '#111111' }};"
                    >
                        {{ $board->title }}
                    </button>
                @endforeach
            </div>
        @endif

        <div class="mt-[18px] grid grid-cols-2 gap-[10px]">
            @forelse ($this->tasks as $task)
             <a
    href="{{ route('page-tasks.show', $task) }}"
    class="relative flex aspect-square flex-col justify-between overflow-hidden rounded-[30px] p-[14px] transition active:scale-[0.99]"
    style="background: {{ $this->taskBackground($task) }};"
>
    <div>
        <div class="mb-[10px] flex items-center justify-between gap-[8px]">
            <span class="rounded-full bg-white/75 px-[9px] py-[5px] text-[11px] font-medium leading-none text-[#111111]">
                {{ $this->taskBadge($task) }}
            </span>

            @if ($task->deadline_at)
                <span class="rounded-full bg-white/75 px-[9px] py-[5px] text-[11px] leading-none text-[#111111]">
                    {{ $task->deadline_at->format('H:i') }}
                </span>
            @endif
        </div>

        <p class="line-clamp-4 text-[18px] font-semibold leading-[1.04] tracking-[-0.04em] text-[#111111]">
            {{ $task->title }}
        </p>
    </div>

    <div>
        <p class="mb-[9px] truncate text-[12px] leading-none text-black/45">
            {{ $task->board?->title ?? 'Разовая задача' }}
        </p>

        <div class="flex items-end justify-between gap-[8px]">
            <div class="flex min-w-0 -space-x-[8px]">
                @forelse ($task->assignees->take(3) as $assignee)
                    <div class="flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-white/90 text-[11px] font-semibold text-[#111111]">
                        {{ mb_substr($assignee->name ?? '?', 0, 1) }}
                    </div>
                @empty
                    <span class="rounded-full bg-white/75 px-[8px] py-[5px] text-[10px] leading-none text-black/45">
                        без исполнителя
                    </span>
                @endforelse
            </div>

            <div class="flex shrink-0 gap-[5px]">
                @if ($task->checklist_items_count > 0)
                    <span class="rounded-full bg-white/75 px-[8px] py-[5px] text-[10px] leading-none text-[#111111]">
                        {{ $task->done_checklist_items_count }}/{{ $task->checklist_items_count }}
                    </span>
                @endif

                @if ($task->comments_count > 0)
                    <span class="rounded-full bg-white/75 px-[8px] py-[5px] text-[10px] leading-none text-[#111111]">
                        💬 {{ $task->comments_count }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</a>
            @empty
                <div class="col-span-2 rounded-[28px] bg-[#F1F1F1] p-[18px]">
                    <p class="text-[18px] font-semibold leading-none">
                        Задач пока нет
                    </p>

                    <p class="mt-[8px] text-[14px] leading-[1.25] text-black/45">
                        Здесь появятся задачи, которые назначены тебе или доступны в твоих досках.
                    </p>

                    @if ($this->canCreateTasks)
                        <x-ui.button
                            type="button"
                            wire:click="openCreateTask"
                            class="mt-[14px]"
                        >
                            Создать задачу
                        </x-ui.button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <div class="border-t border-[#E3EAF0] bg-white/95 px-5 pb-5 pt-4 backdrop-blur supports-[backdrop-filter]:bg-white/80">
        <div class="mb-[10px] grid grid-cols-2 gap-[10px]">
            <x-ui.button
                href="{{ route('page-tasks.rooms') }}"
                variant="secondary"
            >
                Доски
            </x-ui.button>

            @if ($this->canCreateTasks)
                <x-ui.button
                    type="button"
                    wire:click="openCreateTask"
                    variant="secondary"
                >
                    Создать
                </x-ui.button>
            @else
                <x-ui.button
                    href="{{ route('page-tasks.rooms') }}"
                    variant="secondary"
                >
                    Работа
                </x-ui.button>
            @endif
        </div>

        <x-ui.button
            href="{{ route('page-tasks.calendar') }}"
            variant="primary"
        >
            Календарь
        </x-ui.button>
    </div>

    @if ($this->canCreateTasks)
   <x-ui.bottom-sheet model="createTaskOpen">
    <div class="p-[20px]">
        <div class="flex items-start justify-between gap-[14px]">
            <div>
                <p class="text-[26px] font-semibold leading-none tracking-[-0.04em]">
                    Новая задача
                </p>

                <p class="mt-[8px] text-[14px] leading-[1.25] text-black/45">
                    Быстро выдайте поручение сотруднику.
                </p>
            </div>

            <button
                type="button"
                wire:click="closeCreateTask"
                class="flex h-[38px] min-w-[38px] items-center justify-center rounded-full bg-[#F3F3F3]"
            >
                <x-heroicon-o-x-mark class="h-[18px] w-[18px]" />
            </button>
        </div>

        <div class="mt-[18px] space-y-[14px]">

            {{-- Что сделать --}}
            <div>
                <p class="mb-[8px] px-[2px] text-[13px] font-medium text-black/50">
                    Что нужно сделать?
                </p>

                <input
                    type="text"
                    wire:model.live="title"
                    placeholder="Например: проверить объект"
                    class="h-[54px] w-full rounded-[24px] bg-[#F6F6F6] px-[15px] text-[16px] outline-none placeholder:text-black/30 focus:ring-2 focus:ring-black/10"
                >

                @error('title')
                    <p class="mt-[7px] px-[4px] text-[12px] text-red-500">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Кому --}}
            <div>
                <div class="mb-[8px] flex items-center justify-between">
                    <p class="px-[2px] text-[13px] font-medium text-black/50">
                        Кому?
                    </p>

                    @if (count($assigneeIds) > 0)
                        <p class="text-[12px] text-black/35">
                            выбрано: {{ count($assigneeIds) }}
                        </p>
                    @endif
                </div>

                <div class="flex max-h-[108px] flex-wrap gap-[7px] overflow-y-auto">
                    @forelse ($this->members as $user)
                        @php
                            $selected = in_array($user->id, array_map('intval', $assigneeIds), true);
                        @endphp

                        <label
                            class="cursor-pointer rounded-full px-[13px] py-[9px] text-[13px] leading-none transition active:scale-[0.98]"
                            style="
                                background: {{ $selected ? '#111111' : '#F2F2F2' }};
                                color: {{ $selected ? '#FFFFFF' : '#111111' }};
                            "
                        >
                            <input
                                type="checkbox"
                                wire:model.live="assigneeIds"
                                value="{{ $user->id }}"
                                class="hidden"
                            >

                            {{ $user->name }}
                        </label>
                    @empty
                        <p class="w-full rounded-[22px] bg-[#F6F6F6] p-[12px] text-[13px] leading-[1.25] text-black/45">
                            Пользователей пока нет.
                        </p>
                    @endforelse
                </div>
            </div>

            {{-- Срок --}}
            <div>
                <p class="mb-[8px] px-[2px] text-[13px] font-medium text-black/50">
                    Срок
                </p>

                <div class="grid grid-cols-3 gap-[7px]">
                    <button
                        type="button"
                        wire:click="setDeadlineToday"
                        class="h-[42px] rounded-full bg-[#F2F2F2] px-[10px] text-[13px] transition active:scale-[0.98]"
                    >
                        Сегодня
                    </button>

                    <button
                        type="button"
                        wire:click="setDeadlineTomorrow"
                        class="h-[42px] rounded-full bg-[#F2F2F2] px-[10px] text-[13px] transition active:scale-[0.98]"
                    >
                        Завтра
                    </button>

                    <button
                        type="button"
                        wire:click="clearDeadline"
                        class="h-[42px] rounded-full bg-[#F2F2F2] px-[10px] text-[13px] transition active:scale-[0.98]"
                    >
                        Без срока
                    </button>
                </div>

                <input
                    type="datetime-local"
                    wire:model.live="deadlineAt"
                    class="mt-[8px] h-[52px] w-full rounded-[24px] bg-[#F6F6F6] px-[14px] text-[14px] outline-none focus:ring-2 focus:ring-black/10"
                >
            </div>

            {{-- Описание --}}
            <div>
                <p class="mb-[8px] px-[2px] text-[13px] font-medium text-black/50">
                    Описание
                </p>

                <textarea
                    wire:model.live="description"
                    placeholder="Детали, адрес, комментарий..."
                    rows="3"
                    class="w-full resize-none rounded-[24px] bg-[#F6F6F6] px-[14px] py-[12px] text-[14px] outline-none placeholder:text-black/30 focus:ring-2 focus:ring-black/10"
                ></textarea>
            </div>

            {{-- Дополнительно --}}
            <details class="rounded-[24px] bg-[#F6F6F6] p-[13px]">
                <summary class="cursor-pointer text-[13px] font-medium leading-none text-black/45">
                    Дополнительно
                </summary>

                <div class="mt-[12px] space-y-[8px]">
                    <select
                        wire:model.live="priority"
                        class="h-[50px] w-full rounded-[20px] bg-white px-[14px] text-[14px] outline-none focus:ring-2 focus:ring-black/10"
                    >
                        <option value="low">Низкий приоритет</option>
                        <option value="normal">Обычный приоритет</option>
                        <option value="high">Высокий приоритет</option>
                        <option value="urgent">Срочный приоритет</option>
                    </select>

                    <select
                        wire:model.live="boardId"
                        class="h-[50px] w-full rounded-[20px] bg-white px-[14px] text-[14px] outline-none focus:ring-2 focus:ring-black/10"
                    >
                        <option value="">Разовые поручения</option>

                        @foreach ($this->createBoards as $board)
                            <option value="{{ $board->id }}">
                                {{ $board->title }} · {{ $board->room?->title }}
                            </option>
                        @endforeach
                    </select>

                    <select
                        wire:model.live="columnId"
                        class="h-[50px] w-full rounded-[20px] bg-white px-[14px] text-[14px] outline-none focus:ring-2 focus:ring-black/10"
                    >
                        <option value="">Колонка по умолчанию</option>

                        @foreach ($this->columns as $column)
                            <option value="{{ $column->id }}">
                                {{ $column->title }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </details>

            <x-ui.button
                type="button"
                wire:click="createTask"
                wire:loading.attr="disabled"
                wire:target="createTask"
            >
                <span wire:loading.remove wire:target="createTask">
                    Создать задачу
                </span>

                <span wire:loading wire:target="createTask">
                    Создаем...
                </span>
            </x-ui.button>
        </div>
    </div>
</x-ui.bottom-sheet>
    @endif
</div>
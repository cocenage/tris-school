<?php

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
use App\Services\Tasks\TaskTelegramNotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public TaskBoard $taskBoard;

    public bool $createTaskOpen = false;

    public ?int $activeColumnId = null;

    public array $assigneeIds = [];

    public string $title = '';
    public string $description = '';
    public string $priority = 'normal';
    public string $deadlineAt = '';

    public function mount(TaskBoard $taskBoard): void
    {
        $this->taskBoard = $taskBoard->load(['room.users', 'columns']);

        abort_unless($this->canViewBoard(), 403);

        $this->activeColumnId = $this->taskBoard->columns()->orderBy('sort_order')->first()?->id;
    }

    public function getColumnsProperty()
    {
        return $this->taskBoard
            ->columns()
            ->with([
                'tasks' => fn ($query) => $query
                    ->with(['assignees', 'checklistItems', 'comments'])
                    ->withCount([
                        'comments',
                        'checklistItems',
                        'checklistItems as done_checklist_items_count' => fn ($q) => $q->where('is_done', true),
                    ])
                    ->visibleFor(Auth::user())
                    ->orderBy('sort_order')
                    ->orderBy('created_at'),
            ])
            ->orderBy('sort_order')
            ->get();
    }

    public function getActiveColumnProperty()
    {
        return $this->columns->firstWhere('id', $this->activeColumnId)
            ?? $this->columns->first();
    }

    public function getMembersProperty()
    {
        return $this->taskBoard->room
            ->users()
            ->orderBy('name')
            ->get();
    }

    public function getTasksTotalProperty(): int
    {
        return $this->columns->sum(fn ($column) => $column->tasks->count());
    }

    public function openCreateTask(?int $columnId = null): void
    {
        $this->activeColumnId = $columnId ?: $this->activeColumnId;
        $this->createTaskOpen = true;
    }

    public function createTask(TaskTelegramNotificationService $telegram): void
    {
        abort_unless($this->canManageBoard(), 403);

        $this->validate([
            'activeColumnId' => ['required', 'exists:task_board_columns,id'],
            'assigneeIds' => ['array'],
            'assigneeIds.*' => ['exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'deadlineAt' => ['nullable', 'date'],
        ]);

        $column = TaskBoardColumn::query()
            ->where('task_board_id', $this->taskBoard->id)
            ->findOrFail($this->activeColumnId);

        foreach ($this->assigneeIds as $userId) {
            abort_unless(
                $this->taskBoard->room->users()->where('users.id', $userId)->exists(),
                422
            );
        }

        $task = Task::create([
            'task_room_id' => $this->taskBoard->task_room_id,
            'task_board_id' => $this->taskBoard->id,
            'task_board_column_id' => $column->id,
            'created_by' => Auth::id(),
            'assigned_to' => $this->assigneeIds[0] ?? null,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'priority' => $this->priority,
            'status' => $column->slug ?: 'new',
            'deadline_at' => $this->deadlineAt ?: null,
            'sort_order' => Task::query()
                ->where('task_board_column_id', $column->id)
                ->max('sort_order') + 10,
        ]);

        $task->assignees()->sync($this->assigneeIds);

        $telegram->notifyTaskAssignees(
            $task->load(['assignees', 'assignee', 'room', 'board', 'column'])
        );

        $this->reset([
            'assigneeIds',
            'title',
            'description',
            'deadlineAt',
        ]);

        $this->priority = 'normal';
        $this->createTaskOpen = false;

        $this->taskBoard->refresh();
    }

    public function moveTask(
        int $taskId,
        int $columnId,
        TaskTelegramNotificationService $telegram,
    ): void {
        $task = Task::query()
            ->where('task_board_id', $this->taskBoard->id)
            ->visibleFor(Auth::user())
            ->findOrFail($taskId);

        $column = TaskBoardColumn::query()
            ->where('task_board_id', $this->taskBoard->id)
            ->findOrFail($columnId);

        $task->moveToColumn($column);

        $task->refresh()->load(['assignees', 'assignee', 'room', 'board', 'column']);

        $telegram->notifyTaskMoved($task, $column->title);

        $this->taskBoard->refresh();
    }

    public function assigneeNames(Task $task): string
    {
        if ($task->assignees->isNotEmpty()) {
            return $task->assignees->pluck('name')->join(', ');
        }

        return 'Без исполнителя';
    }

    public function taskBackground(Task $task): string
    {
        if ($task->status === 'overdue' || $task->isOverdue()) {
            return '#FFE1E1';
        }

        if ($task->priority === 'urgent') {
            return '#FFE8C7';
        }

        if ($task->status === 'done') {
            return '#E5F7EB';
        }

        if ($task->status === 'in_progress') {
            return '#E4F0FF';
        }

        return '#FFFFFF';
    }

    protected function canViewBoard(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        return $this->taskBoard->room
            ->users()
            ->where('users.id', $user->id)
            ->exists();
    }

    protected function canManageBoard(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        return $this->taskBoard->room
            ->users()
            ->where('users.id', $user->id)
            ->wherePivotIn('role', ['owner', 'manager'])
            ->exists();
    }

    protected function isTaskAdmin($user): bool
    {
        if (method_exists($user, 'canManageTasks')) {
            return $user->canManageTasks();
        }

        return isset($user->role) && in_array($user->role, ['admin', 'supervisor'], true);
    }
};
?>

<x-slot:header>
    <div class="flex h-[73px] w-full items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#E9E9E9]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>

        <span class="max-w-[220px] truncate text-[18px] leading-none">
            {{ $taskBoard->title }}
        </span>

        <button
            type="button"
            wire:click="openCreateTask"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#111111] text-white"
        >
            <x-heroicon-o-plus class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>
    </div>
</x-slot:header>

<div class="h-full w-full overflow-hidden">
    <div class="h-full overflow-y-auto rounded-t-[40px] bg-white px-[15px] pt-[18px] pb-[120px]">

        <div class="mb-[14px]">
            <p class="text-[28px] font-semibold leading-none">
                {{ $taskBoard->title }}
            </p>

            <p class="mt-[7px] text-[13px] text-black/40">
                {{ $taskBoard->room?->title }}
            </p>
        </div>

        @if ($this->tasksTotal === 0)
            <div class="mb-[12px] rounded-[26px] bg-[#F6F6F6] p-[13px]">
                <p class="text-[14px] font-medium leading-none">
                    Доска показывает этапы работы
                </p>

                <p class="mt-[6px] text-[12px] leading-[1.25] text-black/40">
                    Создавай задачи и перемещай их между колонками: Новые → В работе → Проверка → Готово.
                </p>
            </div>
        @endif

        <div class="sticky top-0 z-10 -mx-[15px] bg-white px-[15px] pb-[10px]">
            <div class="flex gap-[7px] overflow-x-auto no-scrollbar">
                @foreach ($this->columns as $column)
                    <button
                        type="button"
                        wire:click="$set('activeColumnId', {{ $column->id }})"
                        class="shrink-0 rounded-full px-[13px] py-[8px] text-[14px]"
                        style="
                            background: {{ $activeColumnId === $column->id ? '#111111' : '#F2F2F2' }};
                            color: {{ $activeColumnId === $column->id ? '#FFFFFF' : '#111111' }};
                        "
                    >
                        {{ $column->title }}
                        <span class="opacity-50">{{ $column->tasks->count() }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @if ($this->activeColumn)
            <section class="mt-[4px]">
                <div class="mb-[10px] flex items-center justify-between">
                    <div>
                        <p class="text-[22px] font-semibold leading-none">
                            {{ $this->activeColumn->title }}
                        </p>

                        <p class="mt-[5px] text-[13px] text-black/40">
                            {{ $this->activeColumn->tasks->count() }} карточек
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="openCreateTask({{ $this->activeColumn->id }})"
                        class="rounded-full bg-[#F2F2F2] px-[13px] py-[8px] text-[13px]"
                    >
                        Добавить
                    </button>
                </div>

                <div class="space-y-[8px]">
                    @forelse ($this->activeColumn->tasks as $task)
                        <div
                            class="rounded-[28px] border border-black/[0.04] p-[14px] shadow-[0_8px_22px_rgba(0,0,0,0.04)]"
                            style="background: {{ $this->taskBackground($task) }};"
                        >
                            <a href="{{ route('page-tasks.show', $task) }}" class="block">
                                <div class="flex items-start justify-between gap-[12px]">
                                    <div class="min-w-0">
                                        <p class="line-clamp-2 text-[17px] font-semibold leading-[1.15]">
                                            {{ $task->title }}
                                        </p>

                                        <p class="mt-[7px] truncate text-[12px] text-black/45">
                                            {{ $this->assigneeNames($task) }}
                                        </p>
                                    </div>

                                    @if ($task->priority !== 'normal')
                                        <span class="shrink-0 rounded-full bg-white/75 px-[9px] py-[5px] text-[12px] leading-none">
                                            {{ $task->displayPriority() }}
                                        </span>
                                    @endif
                                </div>

                                @if ($task->description)
                                    <p class="mt-[10px] line-clamp-2 text-[13px] leading-[1.3] text-black/55">
                                        {{ $task->description }}
                                    </p>
                                @endif

                                <div class="mt-[12px] flex flex-wrap gap-[6px]">
                                    @if ($task->deadline_at)
                                        <span class="rounded-full bg-white/70 px-[9px] py-[5px] text-[12px] leading-none">
                                            {{ $task->deadline_at->format('d.m H:i') }}
                                        </span>
                                    @endif

                                    @if ($task->checklist_items_count > 0)
                                        <span class="rounded-full bg-white/70 px-[9px] py-[5px] text-[12px] leading-none">
                                            {{ $task->done_checklist_items_count }}/{{ $task->checklist_items_count }}
                                        </span>
                                    @endif

                                    @if ($task->comments_count > 0)
                                        <span class="rounded-full bg-white/70 px-[9px] py-[5px] text-[12px] leading-none">
                                            {{ $task->comments_count }} комм.
                                        </span>
                                    @endif
                                </div>
                            </a>

                            <div class="mt-[11px] flex gap-[6px] overflow-x-auto no-scrollbar">
                                @foreach ($this->columns as $moveColumn)
                                    @if ($moveColumn->id !== $task->task_board_column_id)
                                        <button
                                            type="button"
                                            wire:click="moveTask({{ $task->id }}, {{ $moveColumn->id }})"
                                            class="shrink-0 rounded-full bg-black/[0.05] px-[10px] py-[6px] text-[12px]"
                                        >
                                            → {{ $moveColumn->title }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="rounded-[28px] bg-[#F6F6F6] p-[15px]">
                            <p class="text-[15px] text-black/45">
                                В этой колонке пока пусто
                            </p>

                            <x-ui.button wire:click="openCreateTask({{ $this->activeColumn->id }})" class="mt-[12px]">
                                Добавить задачу
                            </x-ui.button>
                        </div>
                    @endforelse
                </div>
            </section>
        @endif
    </div>

    <x-ui.bottom-sheet model="createTaskOpen">
        <div class="p-[20px]">
            <p class="text-[24px] font-semibold leading-none">
                Новая задача
            </p>

            <p class="mt-[7px] text-[14px] leading-[1.25] text-black/45">
                Задача появится в выбранной колонке этой доски.
            </p>

            <div class="mt-[16px] space-y-[10px]">
                <select wire:model="activeColumnId" class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]">
                    @foreach ($this->columns as $column)
                        <option value="{{ $column->id }}">{{ $column->title }}</option>
                    @endforeach
                </select>

                <input
                    type="text"
                    wire:model="title"
                    placeholder="Название задачи"
                    class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]"
                >

                <textarea
                    wire:model="description"
                    placeholder="Описание"
                    rows="3"
                    class="w-full rounded-[20px] bg-[#F6F6F6] px-[14px] py-[12px]"
                ></textarea>

                <div class="rounded-[22px] bg-[#F6F6F6] p-[12px]">
                    <div class="mb-[10px] flex items-start justify-between gap-[10px]">
                        <div>
                            <p class="text-[13px] font-medium leading-none">
                                Исполнители
                            </p>

                            <p class="mt-[5px] text-[12px] leading-[1.2] text-black/40">
                                Тут отображаются только участники пространства
                            </p>
                        </div>

                        <a
                            href="{{ route('page-tasks.room', $taskBoard->room) }}"
                            class="shrink-0 rounded-full bg-white px-[9px] py-[6px] text-[12px] leading-none"
                        >
                            добавить
                        </a>
                    </div>

                    @if ($this->members->count() < 2)
                        <div class="mb-[10px] rounded-[18px] bg-[#FFF3D8] p-[10px]">
                            <p class="text-[12px] leading-[1.25] text-black/55">
                                В пространстве пока мало участников. Добавь людей на странице пространства, чтобы назначать им задачи.
                            </p>
                        </div>
                    @endif

                    <div class="max-h-[190px] space-y-[6px] overflow-y-auto">
                        @foreach ($this->members as $user)
                            <label class="flex items-center gap-[10px] rounded-[18px] bg-white px-[12px] py-[10px]">
                                <input
                                    type="checkbox"
                                    wire:model="assigneeIds"
                                    value="{{ $user->id }}"
                                    class="h-[17px] w-[17px]"
                                >

                                <span class="text-[14px]">
                                    {{ $user->name }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-[8px]">
                    <select wire:model="priority" class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]">
                        <option value="low">Низкий</option>
                        <option value="normal">Обычный</option>
                        <option value="high">Высокий</option>
                        <option value="urgent">Срочный</option>
                    </select>

                    <input
                        type="datetime-local"
                        wire:model="deadlineAt"
                        class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[10px] text-[13px]"
                    >
                </div>

                <x-ui.button wire:click="createTask">
                    Создать задачу
                </x-ui.button>
            </div>
        </div>
    </x-ui.bottom-sheet>
</div>
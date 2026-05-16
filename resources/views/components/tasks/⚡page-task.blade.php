<?php

use App\Models\Task;
use App\Models\TaskBoardColumn;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Services\Tasks\TaskTelegramNotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public Task $task;

    public bool $editOpen = false;
    public bool $moveOpen = false;

  public ?string $title = '';
public ?string $description = '';
    public string $priority = 'normal';
    public string $deadlineAt = '';

    public array $assigneeIds = [];

    public string $newComment = '';
    public string $newChecklistItem = '';

    public function mount(Task $task): void
    {
        $this->task = $task->load([
            'room.users',
            'board.columns',
            'column',
            'assignees',
            'assignee',
            'creator',
            'comments.user',
            'checklistItems',
        ]);

        abort_unless($this->canViewTask(), 403);

        $this->fillForm();
    }

    public function fillForm(): void
    {
        $this->title = $this->task->title;
        $this->description = $this->task->description ?? '';
        $this->priority = $this->task->priority;
        $this->deadlineAt = $this->task->deadline_at?->format('Y-m-d\TH:i') ?? '';

        $this->assigneeIds = $this->task->assignees->pluck('id')->toArray();

        if (empty($this->assigneeIds) && $this->task->assigned_to) {
            $this->assigneeIds = [$this->task->assigned_to];
        }
    }

    public function refreshTask(): void
    {
        $this->task->refresh()->load([
            'room.users',
            'board.columns',
            'column',
            'assignees',
            'assignee',
            'creator',
            'comments.user',
            'checklistItems',
        ]);
    }

    public function getMembersProperty()
    {
        return $this->task->room
            ?->users()
            ->orderBy('name')
            ->get() ?? collect();
    }

    public function getColumnsProperty()
    {
        return $this->task->board
            ?->columns()
            ->orderBy('sort_order')
            ->get() ?? collect();
    }

    public function assigneeNames(): string
    {
        if ($this->task->assignees->isNotEmpty()) {
            return $this->task->assignees->pluck('name')->join(', ');
        }

        return $this->task->assignee?->name ?? 'Не назначены';
    }

    public function save(TaskTelegramNotificationService $telegram): void
    {
        abort_unless($this->canManageTask(), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'assigneeIds' => ['array'],
            'assigneeIds.*' => ['exists:users,id'],
            'deadlineAt' => ['nullable', 'date'],
        ]);

        foreach ($this->assigneeIds as $userId) {
            abort_unless(
                $this->task->room->users()->where('users.id', $userId)->exists(),
                422
            );
        }

        $oldDeadline = $this->task->deadline_at?->format('Y-m-d H:i:s');
        $oldAssigneeIds = $this->task->assignees()->pluck('users.id')->sort()->values()->all();

        $this->task->update([
            'title' => $this->title,
            'description' => $this->description ?: null,
            'priority' => $this->priority,
            'assigned_to' => $this->assigneeIds[0] ?? null,
            'deadline_at' => $this->deadlineAt ?: null,
        ]);

        $this->task->assignees()->sync($this->assigneeIds);

        $this->refreshTask();

        $newDeadline = $this->task->deadline_at?->format('Y-m-d H:i:s');
        $newAssigneeIds = $this->task->assignees()->pluck('users.id')->sort()->values()->all();

        if ($oldDeadline !== $newDeadline) {
            $telegram->notifyDeadlineChanged($this->task);
        }

        if ($oldAssigneeIds !== $newAssigneeIds) {
            $telegram->notifyTaskAssignees($this->task);
        }

        $this->editOpen = false;
    }

    public function moveToColumn(
        int $columnId,
        TaskTelegramNotificationService $telegram,
    ): void {
        abort_unless($this->canManageTask(), 403);

        $column = TaskBoardColumn::query()
            ->where('task_board_id', $this->task->task_board_id)
            ->findOrFail($columnId);

        $this->task->moveToColumn($column);

        $this->refreshTask();

        $telegram->notifyTaskMoved($this->task, $column->title);

        $this->moveOpen = false;
    }

    public function addChecklistItem(): void
    {
        abort_unless($this->canManageTask(), 403);

        $this->validate([
            'newChecklistItem' => ['required', 'string', 'max:255'],
        ]);

        TaskChecklistItem::create([
            'task_id' => $this->task->id,
            'title' => $this->newChecklistItem,
            'sort_order' => $this->task->checklistItems()->max('sort_order') + 10,
        ]);

        $this->newChecklistItem = '';

        $this->refreshTask();
    }

    public function toggleChecklistItem(int $itemId): void
    {
        abort_unless($this->canManageTask(), 403);

        $item = TaskChecklistItem::query()
            ->where('task_id', $this->task->id)
            ->findOrFail($itemId);

        $item->toggleDone(Auth::id());

        $this->refreshTask();
    }

    public function deleteChecklistItem(int $itemId): void
    {
        abort_unless($this->canManageTask(), 403);

        TaskChecklistItem::query()
            ->where('task_id', $this->task->id)
            ->where('id', $itemId)
            ->delete();

        $this->refreshTask();
    }

    public function addComment(TaskTelegramNotificationService $telegram): void
    {
        $this->validate([
            'newComment' => ['required', 'string', 'max:2000'],
        ]);

        $comment = $this->newComment;

        TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => Auth::id(),
            'body' => $comment,
        ]);

        $this->newComment = '';

        $this->refreshTask();

        $telegram->notifyNewComment(
            $this->task,
            Auth::user(),
            $comment,
        );
    }

    public function markDone(): void
    {
        abort_unless($this->canManageTask(), 403);

        $this->task->markAsDone();

        $this->refreshTask();
    }

    public function checklistPercent(): int
    {
        $total = $this->task->checklistItems->count();

        if ($total === 0) {
            return 0;
        }

        $done = $this->task->checklistItems->where('is_done', true)->count();

        return (int) round(($done / $total) * 100);
    }

    public function pageColor(): string
    {
        if ($this->task->status === 'overdue' || $this->task->isOverdue()) {
            return '#FFE1E1';
        }

        if ($this->task->priority === 'urgent') {
            return '#FFE8C7';
        }

        if ($this->task->status === 'done') {
            return '#E5F7EB';
        }

        if ($this->task->status === 'in_progress') {
            return '#E4F0FF';
        }

        return '#F6F6F6';
    }

    protected function canViewTask(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        return $this->task->assigned_to === $user->id
            || $this->task->assignees()->where('users.id', $user->id)->exists()
            || $this->task->room?->users()->where('users.id', $user->id)->exists();
    }

    protected function canManageTask(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        if ($this->task->assigned_to === $user->id) {
            return true;
        }

        if ($this->task->assignees()->where('users.id', $user->id)->exists()) {
            return true;
        }

        return $this->task->room?->users()
            ->where('users.id', $user->id)
            ->wherePivotIn('role', ['owner', 'manager'])
            ->exists() ?? false;
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

        <span class="text-[18px] leading-none">Задача</span>

        <button
            type="button"
            wire:click="$set('editOpen', true)"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#111111] text-white"
        >
            <x-heroicon-o-pencil class="h-[18px] w-[18px] stroke-[2.4]" />
        </button>
    </div>
</x-slot:header>

<div class="h-full w-full overflow-hidden">
    <div class="h-full overflow-y-auto rounded-t-[40px] bg-white px-[15px] pt-[18px] pb-[120px]">

        <div class="rounded-[34px] p-[18px]" style="background: {{ $this->pageColor() }};">
            <div class="mb-[12px] flex flex-wrap gap-[6px]">
                <span class="rounded-full bg-white/70 px-[10px] py-[6px] text-[12px] leading-none">
                    {{ $task->column?->title ?? $task->displayStatus() }}
                </span>

                <span class="rounded-full bg-white/70 px-[10px] py-[6px] text-[12px] leading-none">
                    {{ $task->displayPriority() }}
                </span>

                @if ($task->deadline_at)
                    <span class="rounded-full bg-white/70 px-[10px] py-[6px] text-[12px] leading-none">
                        {{ $task->deadline_at->format('d.m H:i') }}
                    </span>
                @endif
            </div>

            <h1 class="text-[28px] font-semibold leading-[1.05]">
                {{ $task->title }}
            </h1>

            @if ($task->description)
                <p class="mt-[14px] text-[15px] leading-[1.35] text-black/70">
                    {{ $task->description }}
                </p>
            @endif

            <div class="mt-[16px] grid grid-cols-2 gap-[8px]">
                <div class="rounded-[22px] bg-white/70 p-[12px]">
                    <p class="text-[12px] text-black/40">Исполнители</p>
                    <p class="mt-[5px] line-clamp-2 text-[14px] leading-[1.2]">
                        {{ $this->assigneeNames() }}
                    </p>
                </div>

                <div class="rounded-[22px] bg-white/70 p-[12px]">
                    <p class="text-[12px] text-black/40">Доска</p>
                    <p class="mt-[5px] truncate text-[14px] leading-none">
                        {{ $task->board?->title ?? 'Без доски' }}
                    </p>
                </div>
            </div>

            <div class="mt-[12px] flex flex-wrap gap-[8px]">
                <button
                    type="button"
                    wire:click="$set('moveOpen', true)"
                    class="h-[42px] rounded-full bg-white px-[15px] text-[14px]"
                >
                    Переместить
                </button>

                @if ($task->status !== 'done')
                    <button
                        type="button"
                        wire:click="markDone"
                        class="h-[42px] rounded-full bg-[#111111] px-[15px] text-[14px] text-white"
                    >
                        Готово
                    </button>
                @endif
            </div>
        </div>

        <section class="mt-[14px] rounded-[30px] bg-[#F6F6F6] p-[15px]">
            <div class="mb-[12px] flex items-center justify-between">
                <div>
                    <p class="text-[21px] font-semibold leading-none">Чеклист</p>
                    <p class="mt-[5px] text-[13px] text-black/40">
                        {{ $task->checklistProgress() }}
                    </p>
                </div>

                @if ($task->checklistItems->count() > 0)
                    <span class="rounded-full bg-white px-[10px] py-[6px] text-[12px] leading-none">
                        {{ $this->checklistPercent() }}%
                    </span>
                @endif
            </div>

            <div class="space-y-[6px]">
                @foreach ($task->checklistItems as $item)
                    <div class="flex items-center gap-[8px] rounded-[20px] bg-white p-[10px]">
                        <button
                            type="button"
                            wire:click="toggleChecklistItem({{ $item->id }})"
                            class="flex h-[28px] min-w-[28px] items-center justify-center rounded-full {{ $item->is_done ? 'bg-[#111111] text-white' : 'bg-[#EFEFEF]' }}"
                        >
                            @if ($item->is_done)
                                ✓
                            @endif
                        </button>

                        <p class="flex-1 text-[14px] leading-[1.2] {{ $item->is_done ? 'text-black/35 line-through' : '' }}">
                            {{ $item->title }}
                        </p>

                        <button
                            type="button"
                            wire:click="deleteChecklistItem({{ $item->id }})"
                            class="text-[12px] text-black/30"
                        >
                            удалить
                        </button>
                    </div>
                @endforeach
            </div>

            <div class="mt-[10px] flex gap-[8px]">
                <input
                    type="text"
                    wire:model="newChecklistItem"
                    placeholder="Добавить пункт"
                    class="h-[44px] min-w-0 flex-1 rounded-full bg-white px-[14px] text-[14px]"
                >

                <button
                    type="button"
                    wire:click="addChecklistItem"
                    class="flex h-[44px] min-w-[44px] items-center justify-center rounded-full bg-[#111111] text-white"
                >
                    +
                </button>
            </div>
        </section>

        <section class="mt-[14px] rounded-[30px] bg-[#F6F6F6] p-[15px]">
            <p class="mb-[7px] text-[21px] font-semibold leading-none">
                Обсуждение
            </p>

            <p class="mb-[12px] text-[13px] leading-[1.25] text-black/40">
                Все вопросы по задаче лучше писать здесь, чтобы история не терялась в Telegram.
            </p>

            <div class="space-y-[8px]">
                @forelse ($task->comments as $comment)
                    <div class="rounded-[22px] bg-white p-[12px]">
                        <div class="mb-[7px] flex items-center justify-between gap-[10px]">
                            <p class="truncate text-[13px] font-medium leading-none">
                                {{ $comment->user?->name ?? 'Пользователь' }}
                            </p>

                            <p class="shrink-0 text-[11px] text-black/30">
                                {{ $comment->created_at->format('d.m H:i') }}
                            </p>
                        </div>

                        <p class="text-[14px] leading-[1.35] text-black/75">
                            {{ $comment->body }}
                        </p>
                    </div>
                @empty
                    <p class="rounded-[22px] bg-white p-[12px] text-[14px] text-black/40">
                        Комментариев пока нет
                    </p>
                @endforelse
            </div>

            <div class="mt-[10px] space-y-[8px]">
                <textarea
                    wire:model="newComment"
                    placeholder="Написать комментарий"
                    rows="3"
                    class="w-full rounded-[22px] bg-white px-[14px] py-[12px] text-[14px]"
                ></textarea>

                <x-ui.button wire:click="addComment">
                    Отправить
                </x-ui.button>
            </div>
        </section>
    </div>

    <x-ui.bottom-sheet model="moveOpen">
        <div class="p-[20px]">
            <p class="text-[24px] font-semibold leading-none">
                Переместить
            </p>

            <div class="mt-[16px] space-y-[8px]">
                @foreach ($this->columns as $column)
                    <button
                        type="button"
                        wire:click="moveToColumn({{ $column->id }})"
                        class="flex h-[54px] w-full items-center justify-between rounded-[22px] bg-[#F6F6F6] px-[15px] text-left"
                    >
                        <span>{{ $column->title }}</span>

                        @if ($task->task_board_column_id === $column->id)
                            <span class="text-[13px] text-black/35">сейчас</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </x-ui.bottom-sheet>

    <x-ui.bottom-sheet model="editOpen">
        <div class="p-[20px]">
            <p class="text-[24px] font-semibold leading-none">
                Редактировать
            </p>

            <div class="mt-[16px] space-y-[10px]">
                <input
                    type="text"
                    wire:model="title"
                    placeholder="Название"
                    class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]"
                >

                <textarea
                    wire:model="description"
                    placeholder="Описание"
                    rows="3"
                    class="w-full rounded-[20px] bg-[#F6F6F6] px-[14px] py-[12px]"
                ></textarea>

                <div class="rounded-[22px] bg-[#F6F6F6] p-[12px]">
                    <div class="mb-[10px]">
                        <p class="text-[13px] font-medium leading-none">
                            Исполнители
                        </p>

                        <p class="mt-[5px] text-[12px] leading-[1.2] text-black/40">
                            Тут отображаются только участники пространства
                        </p>
                    </div>

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

                <x-ui.button wire:click="save">
                    Сохранить
                </x-ui.button>
            </div>
        </div>
    </x-ui.bottom-sheet>
</div>
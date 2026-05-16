<?php

use App\Models\TaskBoard;
use App\Models\TaskRoom;
use App\Models\User;
use App\Services\Tasks\TaskTelegramNotificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public TaskRoom $taskRoom;

    public bool $createBoardOpen = false;
    public bool $membersOpen = false;

    public string $boardTitle = '';
    public string $boardDescription = '';

    public ?int $memberId = null;
    public string $memberRole = 'member';

    public function mount(TaskRoom $taskRoom): void
    {
        $this->taskRoom = $taskRoom->load(['users']);

        abort_unless($this->canViewRoom(), 403);
    }

    public function getBoardsProperty()
    {
        return $this->taskRoom
            ->boards()
            ->withCount([
                'tasks',
                'tasks as active_tasks_count' => fn ($q) => $q->whereNotIn('status', ['done', 'cancelled']),
                'tasks as overdue_tasks_count' => fn ($q) => $q
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->whereNotNull('deadline_at')
                    ->where('deadline_at', '<', now()),
            ])
            ->get();
    }

    public function getMembersProperty()
    {
        return $this->taskRoom
            ->users()
            ->orderBy('name')
            ->get();
    }

    public function getAvailableUsersProperty()
    {
        $currentIds = $this->taskRoom->users()->pluck('users.id')->toArray();

        return User::query()
            ->whereNotIn('id', $currentIds)
            ->orderBy('name')
            ->get();
    }

    public function createBoard(): void
    {
        abort_unless($this->canManageRoom(), 403);

        $this->validate([
            'boardTitle' => ['required', 'string', 'max:255'],
            'boardDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $board = TaskBoard::create([
            'task_room_id' => $this->taskRoom->id,
            'created_by' => Auth::id(),
            'title' => $this->boardTitle,
            'description' => $this->boardDescription ?: null,
            'status' => 'active',
            'sort_order' => $this->taskRoom->boards()->max('sort_order') + 10,
        ]);

        $board->createDefaultColumns();

        $this->reset(['boardTitle', 'boardDescription']);
        $this->createBoardOpen = false;

        $this->redirectRoute('page-tasks.board', $board, navigate: true);
    }

    public function addMember(TaskTelegramNotificationService $telegram): void
    {
        abort_unless($this->canManageRoom(), 403);

        $this->validate([
            'memberId' => ['required', 'exists:users,id'],
            'memberRole' => ['required', 'in:owner,manager,member'],
        ]);

        $this->taskRoom->users()->syncWithoutDetaching([
            $this->memberId => ['role' => $this->memberRole],
        ]);

        $user = User::find($this->memberId);

        if ($user) {
            $telegram->sendRoomAdded($this->taskRoom, $user);
        }

        $this->memberId = null;
        $this->memberRole = 'member';

        $this->taskRoom->refresh();
    }

    public function removeMember(int $userId): void
    {
        abort_unless($this->canManageRoom(), 403);

        if ($userId === Auth::id()) {
            return;
        }

        $this->taskRoom->users()->detach($userId);
        $this->taskRoom->refresh();
    }

    protected function canViewRoom(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        return $this->taskRoom->users()
            ->where('users.id', $user->id)
            ->exists();
    }

    protected function canManageRoom(): bool
    {
        $user = Auth::user();

        if ($this->isTaskAdmin($user)) {
            return true;
        }

        return $this->taskRoom->users()
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
            {{ $taskRoom->title }}
        </span>

        <button
            type="button"
            wire:click="$set('createBoardOpen', true)"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#111111] text-white"
        >
            <x-heroicon-o-plus class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>
    </div>
</x-slot:header>

<div class="h-full w-full overflow-hidden">
    <div class="h-full overflow-y-auto rounded-t-[40px] bg-white px-[15px] pt-[18px] pb-[120px]">

        <div class="rounded-[34px] bg-[#111111] p-[18px] text-white">
            <p class="text-[27px] font-semibold leading-none">
                {{ $taskRoom->title }}
            </p>

            @if ($taskRoom->description)
                <p class="mt-[9px] text-[14px] leading-[1.25] text-white/60">
                    {{ $taskRoom->description }}
                </p>
            @endif

            <div class="mt-[16px] flex gap-[7px]">
                <button
                    type="button"
                    wire:click="$set('membersOpen', true)"
                    class="rounded-full bg-white/12 px-[12px] py-[8px] text-[13px]"
                >
                    Участники: {{ $this->members->count() }}
                </button>

                <button
                    type="button"
                    wire:click="$set('createBoardOpen', true)"
                    class="rounded-full bg-white px-[12px] py-[8px] text-[13px] text-[#111111]"
                >
                    Создать доску
                </button>
            </div>
        </div>

        @if ($this->members->count() < 2 || $this->boards->isEmpty())
            <div class="mt-[10px] rounded-[30px] bg-[#F6F6F6] p-[15px]">
                <p class="text-[18px] font-semibold leading-none">
                    Настройка пространства
                </p>

                <p class="mt-[7px] text-[13px] leading-[1.25] text-black/45">
                    Чтобы удобно выдавать задачи, сначала добавь участников, потом создай рабочую доску.
                </p>

                <div class="mt-[14px] space-y-[7px]">
                    <button
                        type="button"
                        wire:click="$set('membersOpen', true)"
                        class="flex w-full items-center justify-between rounded-[22px] bg-white p-[12px] text-left"
                    >
                        <div>
                            <p class="text-[14px] font-medium leading-none">
                                1. Участники
                            </p>

                            <p class="mt-[6px] text-[12px] text-black/40">
                                Исполнителями могут быть только люди из этого пространства
                            </p>
                        </div>

                        <span class="rounded-full bg-[#F6F6F6] px-[9px] py-[5px] text-[12px] leading-none">
                            {{ $this->members->count() }}
                        </span>
                    </button>

                    <button
                        type="button"
                        wire:click="$set('createBoardOpen', true)"
                        class="flex w-full items-center justify-between rounded-[22px] bg-white p-[12px] text-left"
                    >
                        <div>
                            <p class="text-[14px] font-medium leading-none">
                                2. Рабочая доска
                            </p>

                            <p class="mt-[6px] text-[12px] text-black/40">
                                На доске задачи проходят этапы: Новые → В работе → Проверка → Готово
                            </p>
                        </div>

                        <span class="rounded-full bg-[#F6F6F6] px-[9px] py-[5px] text-[12px] leading-none">
                            {{ $this->boards->count() }}
                        </span>
                    </button>
                </div>
            </div>
        @endif

        <section class="mt-[18px]">
            <div class="mb-[10px]">
                <p class="text-[22px] font-semibold leading-none">Доски</p>
                <p class="mt-[5px] text-[13px] text-black/40">Рабочие процессы пространства</p>
            </div>

            <div class="space-y-[8px]">
                @forelse ($this->boards as $board)
                    <a
                        href="{{ route('page-tasks.board', $board) }}"
                        class="block rounded-[28px] bg-[#F6F6F6] p-[14px]"
                    >
                        <div class="flex items-start justify-between gap-[12px]">
                            <div class="min-w-0">
                                <p class="truncate text-[18px] font-semibold leading-none">
                                    {{ $board->title }}
                                </p>

                                @if ($board->description)
                                    <p class="mt-[8px] line-clamp-2 text-[13px] leading-[1.25] text-black/45">
                                        {{ $board->description }}
                                    </p>
                                @endif
                            </div>

                            <x-heroicon-o-chevron-right class="h-[20px] w-[20px] shrink-0 text-black/35" />
                        </div>

                        <div class="mt-[12px] flex flex-wrap gap-[6px]">
                            <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                                {{ $board->active_tasks_count }} активных
                            </span>

                            <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                                {{ $board->tasks_count }} всего
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
                        <p class="text-[16px] font-medium">Досок пока нет</p>
                        <p class="mt-[6px] text-[13px] text-black/45">
                            Создай доску, и внутри нее появятся колонки задач.
                        </p>

                        <x-ui.button wire:click="$set('createBoardOpen', true)" class="mt-[14px]">
                            Создать доску
                        </x-ui.button>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <x-ui.bottom-sheet model="createBoardOpen">
        <div class="p-[20px]">
            <p class="text-[24px] font-semibold leading-none">Новая доска</p>

            <p class="mt-[7px] text-[14px] leading-[1.25] text-black/45">
                Например: ежедневные задачи, проверки, расходники, обучение.
            </p>

            <div class="mt-[16px] space-y-[10px]">
                <input
                    type="text"
                    wire:model="boardTitle"
                    placeholder="Название доски"
                    class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]"
                >

                <textarea
                    wire:model="boardDescription"
                    placeholder="Описание"
                    rows="4"
                    class="w-full rounded-[20px] bg-[#F6F6F6] px-[14px] py-[12px]"
                ></textarea>

                <x-ui.button wire:click="createBoard">
                    Создать доску
                </x-ui.button>
            </div>
        </div>
    </x-ui.bottom-sheet>

    <x-ui.bottom-sheet model="membersOpen">
        <div class="p-[20px]">
            <p class="text-[24px] font-semibold leading-none">
                Участники пространства
            </p>

            <p class="mt-[7px] text-[14px] leading-[1.25] text-black/45">
                Только участники пространства могут быть исполнителями задач на его досках.
            </p>

            <div class="mt-[16px] space-y-[7px]">
                @foreach ($this->members as $member)
                    <div class="flex items-center justify-between gap-[10px] rounded-[22px] bg-[#F6F6F6] p-[12px]">
                        <div class="min-w-0">
                            <p class="truncate text-[15px] font-medium leading-none">
                                {{ $member->name }}
                            </p>

                            <p class="mt-[6px] text-[12px] text-black/40">
                                {{ $member->pivot->role }}
                            </p>
                        </div>

                        @if ($member->id !== auth()->id())
                            <button
                                type="button"
                                wire:click="removeMember({{ $member->id }})"
                                class="rounded-full bg-white px-[10px] py-[6px] text-[12px]"
                            >
                                убрать
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-[16px] space-y-[10px]">
                <div class="rounded-[22px] bg-[#EEF5FF] p-[12px]">
                    <p class="text-[13px] leading-[1.25] text-[#213259]">
                        Если человека нет в списке исполнителей при создании задачи — сначала добавь его сюда.
                    </p>
                </div>

                <select wire:model="memberId" class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]">
                    <option value="">Добавить пользователя</option>
                    @foreach ($this->availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>

                <select wire:model="memberRole" class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px]">
                    <option value="member">Участник</option>
                    <option value="manager">Ответственный</option>
                    <option value="owner">Владелец</option>
                </select>

                <x-ui.button wire:click="addMember">
                    Добавить в пространство
                </x-ui.button>
            </div>
        </div>
    </x-ui.bottom-sheet>
</div>
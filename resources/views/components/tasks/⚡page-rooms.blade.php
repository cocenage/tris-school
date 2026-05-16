<?php

use App\Models\TaskRoom;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public bool $createRoomOpen = false;

    public ?string $title = '';
    public ?string $description = '';

    public function getRoomsProperty()
    {
        $user = Auth::user();

        return TaskRoom::query()
            ->where(function ($query) use ($user) {
                if ($this->isTaskAdmin($user)) {
                    return;
                }

                $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
            })
            ->withCount([
                'users',
                'boards',
                'tasks as active_tasks_count' => fn ($query) => $query
                    ->whereNotIn('status', ['done', 'cancelled']),

                'tasks as overdue_tasks_count' => fn ($query) => $query
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->whereNotNull('deadline_at')
                    ->where('deadline_at', '<', now()),
            ])
            ->orderByRaw("status = 'archived'")
            ->orderBy('title')
            ->get();
    }

    public function openCreateRoom(): void
    {
        $this->createRoomOpen = true;
    }

public function getCanCreateRoomsProperty(): bool
{
    return $this->isTaskAdmin(Auth::user());
}

    public function closeCreateRoom(): void
    {
        $this->createRoomOpen = false;

        $this->title = '';
        $this->description = '';

        $this->resetValidation();
    }

    public function createRoom(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'title.required' => 'Напиши название пространства.',
            'title.max' => 'Название слишком длинное.',
            'description.max' => 'Описание слишком длинное.',
        ]);

        $room = TaskRoom::create([
            'created_by' => Auth::id(),
            'title' => trim((string) $this->title),
            'description' => trim((string) $this->description) !== ''
                ? trim((string) $this->description)
                : null,
            'status' => 'active',
            'color' => '#F6F6F6',
        ]);

        $room->users()->syncWithoutDetaching([
            Auth::id() => ['role' => 'owner'],
        ]);

        $this->title = '';
        $this->description = '';
        $this->createRoomOpen = false;

        $this->redirectRoute('page-tasks.room', $room, navigate: true);
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
    <div class="flex h-[73px] w-full items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#E9E9E9]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>

        <span class="text-[18px] leading-none">
            Пространства
        </span>

        <button
            type="button"
            x-data
            @click="$dispatch('open-create-room')"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full bg-[#111111] text-white"
        >
            <x-heroicon-o-plus class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>
    </div>
</x-slot:header>

<div
    x-data
    x-on:open-create-room.window="$wire.call('openCreateRoom')"
    class="h-full w-full overflow-hidden"
>
    <div class="h-full overflow-y-auto rounded-t-[40px] bg-white px-[15px] pt-[18px] pb-[120px]">

        <div class="mb-[14px]">
            <p class="text-[30px] font-semibold leading-none">
                Пространства
            </p>

            <p class="mt-[8px] text-[14px] leading-[1.25] text-black/45">
                Пространство — это место, где собраны люди, рабочие доски и задачи.
            </p>
        </div>

        <div class="mb-[14px] rounded-[30px] bg-[#F6F6F6] p-[15px]">
            <div class="flex items-start justify-between gap-[12px]">
                <div>
                    <p class="text-[17px] font-semibold leading-none">
                        Как начать
                    </p>

                    <p class="mt-[7px] text-[13px] leading-[1.25] text-black/45">
                        Создай пространство, добавь участников, потом создай доску и выдавай задачи.
                    </p>
                </div>

                <div class="flex h-[38px] min-w-[38px] items-center justify-center rounded-full bg-white">
                    <x-heroicon-o-light-bulb class="h-[19px] w-[19px]" />
                </div>
            </div>

            <div class="mt-[13px] grid grid-cols-3 gap-[7px]">
                <div class="rounded-[20px] bg-white p-[10px]">
                    <p class="text-[17px] font-semibold leading-none">1</p>
                    <p class="mt-[7px] text-[12px] leading-[1.15] text-black/55">
                        Пространство
                    </p>
                </div>

                <div class="rounded-[20px] bg-white p-[10px]">
                    <p class="text-[17px] font-semibold leading-none">2</p>
                    <p class="mt-[7px] text-[12px] leading-[1.15] text-black/55">
                        Участники
                    </p>
                </div>

                <div class="rounded-[20px] bg-white p-[10px]">
                    <p class="text-[17px] font-semibold leading-none">3</p>
                    <p class="mt-[7px] text-[12px] leading-[1.15] text-black/55">
                        Доска
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-[9px]">
            @forelse ($this->rooms as $room)
                <a
                    href="{{ route('page-tasks.room', $room) }}"
                    class="block rounded-[30px] bg-[#F6F6F6] p-[15px] active:scale-[0.99] transition"
                >
                    <div class="flex items-start justify-between gap-[12px]">
                        <div class="min-w-0">
                            <div class="flex items-center gap-[7px]">
                                <p class="truncate text-[19px] font-semibold leading-none">
                                    {{ $room->title }}
                                </p>

                                @if ($room->status === 'archived')
                                    <span class="shrink-0 rounded-full bg-white px-[8px] py-[4px] text-[11px] leading-none text-black/45">
                                        архив
                                    </span>
                                @endif
                            </div>

                            @if ($room->description)
                                <p class="mt-[8px] line-clamp-2 text-[13px] leading-[1.25] text-black/45">
                                    {{ $room->description }}
                                </p>
                            @else
                                <p class="mt-[8px] text-[13px] leading-[1.25] text-black/35">
                                    Без описания
                                </p>
                            @endif
                        </div>

                        <div class="flex h-[36px] min-w-[36px] items-center justify-center rounded-full bg-white">
                            <x-heroicon-o-chevron-right class="h-[18px] w-[18px] text-black/35" />
                        </div>
                    </div>

                    <div class="mt-[13px] flex flex-wrap gap-[6px]">
                        <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                            {{ $room->users_count }} людей
                        </span>

                        <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                            {{ $room->boards_count }} досок
                        </span>

                        <span class="rounded-full bg-white px-[9px] py-[5px] text-[12px] leading-none">
                            {{ $room->active_tasks_count }} задач
                        </span>

                        @if ($room->overdue_tasks_count > 0)
                            <span class="rounded-full bg-[#FFE1E1] px-[9px] py-[5px] text-[12px] leading-none">
                                {{ $room->overdue_tasks_count }} горит
                            </span>
                        @endif
                    </div>
                </a>
            @empty
                <div class="rounded-[30px] bg-[#F6F6F6] p-[16px]">
                    <div class="flex items-start justify-between gap-[12px]">
                        <div>
                            <p class="text-[17px] font-semibold leading-none">
                                Пространств пока нет
                            </p>

                            <p class="mt-[7px] text-[13px] leading-[1.25] text-black/45">
                                Создай пространство для объекта, отдела или процесса. Потом добавь туда людей и доску.
                            </p>
                        </div>

                        <div class="flex h-[38px] min-w-[38px] items-center justify-center rounded-full bg-white">
                            <x-heroicon-o-folder-plus class="h-[20px] w-[20px]" />
                        </div>
                    </div>

                    <x-ui.button
                        type="button"
                        wire:click="openCreateRoom"
                        class="mt-[14px]"
                    >
                        Создать пространство
                    </x-ui.button>
                </div>
            @endforelse
        </div>
    </div>

    <x-ui.bottom-sheet model="createRoomOpen">
        <div class="p-[20px]">
            <div class="flex items-start justify-between gap-[14px]">
                <div>
                    <p class="text-[24px] font-semibold leading-none">
                        Новое пространство
                    </p>

                    <p class="mt-[7px] text-[14px] leading-[1.25] text-black/45">
                        Например: объект, отдел, команда, расходники, финансы или обучение.
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="closeCreateRoom"
                    class="flex h-[36px] min-w-[36px] items-center justify-center rounded-full bg-[#F6F6F6]"
                >
                    <x-heroicon-o-x-mark class="h-[18px] w-[18px]" />
                </button>
            </div>

            <div class="mt-[16px] space-y-[10px]">
                <div>
                    <input
                        type="text"
                        wire:model.live="title"
                        placeholder="Название пространства"
                        class="h-[50px] w-full rounded-[20px] bg-[#F6F6F6] px-[14px] outline-none placeholder:text-black/30 focus:ring-2 focus:ring-black/10"
                    >

                    @error('title')
                        <p class="mt-[6px] px-[4px] text-[12px] text-red-500">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <textarea
                        wire:model.live="description"
                        placeholder="Описание"
                        rows="4"
                        class="w-full resize-none rounded-[20px] bg-[#F6F6F6] px-[14px] py-[12px] outline-none placeholder:text-black/30 focus:ring-2 focus:ring-black/10"
                    ></textarea>

                    @error('description')
                        <p class="mt-[6px] px-[4px] text-[12px] text-red-500">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="rounded-[22px] bg-[#EEF5FF] p-[12px]">
                    <p class="text-[13px] leading-[1.25] text-[#213259]">
                        После создания ты автоматически станешь владельцем пространства. Участников добавим на следующем экране.
                    </p>
                </div>

                <x-ui.button
                    type="button"
                    wire:click="createRoom"
                    wire:loading.attr="disabled"
                    wire:target="createRoom"
                >
                    <span wire:loading.remove wire:target="createRoom">
                        Создать пространство
                    </span>

                    <span wire:loading wire:target="createRoom">
                        Создаем...
                    </span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.bottom-sheet>
</div>
<?php

namespace App\Services\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
use App\Models\TaskRoom;
use App\Models\User;

class DefaultTaskBoardService
{
    public function room(): TaskRoom
    {
        return TaskRoom::query()->firstOrCreate(
            [
                'title' => 'Общие задачи',
            ],
            [
                'created_by' => null,
                'description' => 'Системная команда для быстрых разовых поручений.',
                'status' => 'active',
                'color' => '#F6F6F6',
                'icon' => 'bolt',
            ]
        );
    }

    public function board(): TaskBoard
    {
        $room = $this->room();

        $board = TaskBoard::query()->firstOrCreate(
            [
                'task_room_id' => $room->id,
                'title' => 'Разовые поручения',
            ],
            [
                'created_by' => null,
                'description' => 'Быстрые задачи без отдельной настройки доски.',
                'status' => 'active',
                'sort_order' => 0,
            ]
        );

        if ($board->columns()->count() === 0) {
            $board->createDefaultColumns();
        }

        return $board->fresh(['room', 'columns']);
    }

    public function defaultColumn(): TaskBoardColumn
    {
        $board = $this->board();

        return $board->columns()
            ->where('slug', 'new')
            ->first()
            ?? $board->columns()->orderBy('sort_order')->first()
            ?? $board->columns()->create([
                'title' => 'Новые',
                'slug' => 'new',
                'color' => '#F4F4F4',
                'sort_order' => 10,
                'is_done_column' => false,
            ]);
    }

    public function ensureUserInDefaultRoom(User $user, string $role = 'member'): void
    {
        $this->room()->users()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);
    }
}
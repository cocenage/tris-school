<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FilamentTaskService
{
    public const PERSONAL_ROOM = 'Персональные поручения';

    public function save(User $actor, array $input, ?Task $record = null): Task
    {
        abort_unless($actor->isApproved() && $actor->role === 'admin', 403);
        $input['title'] = trim((string) ($input['title'] ?? ''));
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'starts_at' => ['nullable', 'date'],
            'deadline_at' => array_filter(['nullable', 'date', filled($input['starts_at'] ?? null) ? 'after_or_equal:starts_at' : null]),
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('status', 'approved')->where('is_active', true)],
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'review', 'done', 'overdue', 'cancelled'])],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $record): Task {
            $ids = array_values(array_map('intval', $data['assignee_ids']));
            $task = $record === null ? new Task : Task::query()->lockForUpdate()->findOrFail($record->getKey());
            if (! $task->exists) {
                $room = TaskRoom::query()->firstOrCreate(['title' => self::PERSONAL_ROOM], [
                    'status' => 'active', 'color' => '#F6F6F6', 'icon' => 'bolt',
                ]);
                $room = TaskRoom::query()->lockForUpdate()->findOrFail($room->id);
                abort_if($room->users()->exists(), 409, 'Комната персональных поручений должна оставаться закрытой.');
                $board = TaskBoard::query()->firstOrCreate([
                    'task_room_id' => $room->id, 'title' => self::PERSONAL_ROOM,
                ], ['status' => 'active', 'sort_order' => 0]);
                if (! $board->columns()->exists()) {
                    $board->createDefaultColumns();
                }
                $column = $board->columns()->where('slug', 'new')->firstOrFail();
                $task->fill([
                    'task_room_id' => $room->id, 'task_board_id' => $board->id,
                    'task_board_column_id' => $column->id, 'created_by' => $actor->id,
                    'sort_order' => ((int) Task::query()->where('task_board_column_id', $column->id)->max('sort_order')) + 10,
                ]);
            }
            $status = $record === null ? 'new' : ($data['status'] ?? $task->status);
            if ($record !== null && $status !== $task->status && $task->board) {
                $column = $task->board->columns()->where('slug', $status)->first();
                if ($column !== null) {
                    $task->task_board_column_id = $column->id;
                }
            }
            $task->fill([
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'assigned_to' => $ids[0], 'priority' => $data['priority'],
                'starts_at' => $data['starts_at'] ?? null, 'deadline_at' => $data['deadline_at'] ?? null,
                'status' => $status,
                'completed_at' => $status === 'done' ? ($task->completed_at ?? now()) : null,
                'cancelled_at' => $status === 'cancelled' ? ($task->cancelled_at ?? now()) : null,
            ]);
            $task->save();
            $task->assignees()->sync($ids);

            return $task->fresh(['assignees', 'assignee', 'board', 'room', 'column']);
        });
    }
}

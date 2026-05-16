<?php

namespace App\Services\Calendar;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskCalendarEventService
{
    public function getEvents(User $user, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        return Task::query()
            ->with(['room', 'assignee'])
            ->visibleFor($user)
            ->whereNotNull('deadline_at')
            ->whereBetween('deadline_at', [
                $rangeStart->copy()->startOfDay(),
                $rangeEnd->copy()->endOfDay(),
            ])
            ->where('status', '!=', 'cancelled')
            ->get()
            ->map(fn (Task $task) => $this->mapTask($task));
    }

    protected function mapTask(Task $task): array
    {
        $deadline = $task->deadline_at->copy();

        $assigneeName = $task->assignee?->name ?? 'Без исполнителя';
        $roomTitle = $task->room?->title ?? 'Без комнаты';

        return [
            'id' => 'task_' . $task->id,
            'title' => $task->title,
            'description' => trim($roomTitle . ' · ' . $assigneeName . ' · до ' . $deadline->format('H:i')),
            'short' => $this->shortTitle($task),
            'type' => 'tasks',
            'priority' => $this->calendarPriority($task),
            'start' => $deadline->copy()->startOfDay(),
            'end' => $deadline->copy()->startOfDay(),
            'style' => $this->taskStyle($task),

            'source' => 'task',
            'source_id' => $task->id,
            'url' => route('page-tasks.show', $task),

            'meta' => [
                'room' => $roomTitle,
                'assignee' => $assigneeName,
                'status' => $task->status,
                'status_label' => $task->displayStatus(),
                'priority' => $task->priority,
                'priority_label' => $task->displayPriority(),
                'deadline_time' => $deadline->format('H:i'),
            ],
        ];
    }

    protected function shortTitle(Task $task): string
    {
        $emoji = match (true) {
            $task->status === 'overdue' || $task->isOverdue() => '🔥',
            $task->priority === 'urgent' => '⚡',
            $task->status === 'done' => '✓',
            default => '📝',
        };

        return mb_strimwidth($emoji . ' ' . $task->title, 0, 16, '...');
    }

    protected function calendarPriority(Task $task): int
    {
        if ($task->status === 'overdue' || $task->isOverdue()) {
            return 160;
        }

        if ($task->priority === 'urgent') {
            return 150;
        }

        if ($task->priority === 'high') {
            return 140;
        }

        if ($task->status === 'done') {
            return 40;
        }

        return 120;
    }

    protected function taskStyle(Task $task): string
    {
        if ($task->status === 'overdue' || $task->isOverdue()) {
            return 'background:#FFD6D6;color:#111111;';
        }

        if ($task->priority === 'urgent') {
            return 'background:#FFE0C2;color:#111111;';
        }

        return match ($task->status) {
            'new' => 'background:#DDEBFF;color:#111111;',
            'in_progress' => 'background:#CFE8FF;color:#111111;',
            'done' => 'background:#DDF3E4;color:#111111;',
            default => 'background:#DDEBFF;color:#111111;',
        };
    }
}
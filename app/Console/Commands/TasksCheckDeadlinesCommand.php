<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\Tasks\TaskTelegramNotificationService;
use Illuminate\Console\Command;

class TasksCheckDeadlinesCommand extends Command
{
    protected $signature = 'tasks:check-deadlines';

    protected $description = 'Check task deadlines and send Telegram reminders';

    public function handle(TaskTelegramNotificationService $telegram): int
    {
        $now = now();

        Task::query()
            ->with(['assignees', 'assignee', 'room', 'board', 'column'])
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereNotNull('deadline_at')
            ->chunkById(100, function ($tasks) use ($telegram, $now) {
                foreach ($tasks as $task) {
                    if ($task->deadline_at->isPast()) {
                        if ($task->status !== 'overdue') {
                            $task->markAsOverdue();
                        }

                        $telegram->notifyOverdue($task);
                        continue;
                    }

                    $minutesLeft = $now->diffInMinutes($task->deadline_at, false);

                    if ($minutesLeft <= 60) {
                        $telegram->notifyDeadlineSoon($task, 'deadline_1h');
                        continue;
                    }

                    if ($minutesLeft <= 180) {
                        $telegram->notifyDeadlineSoon($task, 'deadline_3h');
                        continue;
                    }

                    if ($minutesLeft <= 1440) {
                        $telegram->notifyDeadlineSoon($task, 'deadline_24h');
                    }
                }
            });

        return self::SUCCESS;
    }
}
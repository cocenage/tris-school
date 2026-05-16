<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskNotification;
use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaskTelegramNotificationService
{
    public function sendRoomAdded(TaskRoom $room, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'room_added_' . $room->id,
            message: $this->roomAddedMessage($room),
            room: $room,
        );
    }

    public function sendTaskAssigned(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_assigned',
            message: $this->taskAssignedMessage($task),
            task: $task,
        );
    }

    public function sendDeadlineSoon(Task $task, User $user, string $type): void
    {
        $this->sendToUser(
            user: $user,
            type: $type,
            message: $this->deadlineSoonMessage($task),
            task: $task,
        );
    }

    public function sendTaskOverdue(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_overdue',
            message: $this->taskOverdueMessage($task),
            task: $task,
        );
    }

    public function sendDeadlineChanged(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'deadline_changed_' . now()->timestamp,
            message: $this->deadlineChangedMessage($task),
            task: $task,
            allowRepeat: true,
        );
    }

    public function sendNewComment(Task $task, User $user, User $author, string $comment): void
    {
        if ($user->id === $author->id) {
            return;
        }

        $this->sendToUser(
            user: $user,
            type: 'new_comment_' . now()->timestamp . '_' . $author->id,
            message: $this->newCommentMessage($task, $author, $comment),
            task: $task,
            allowRepeat: true,
        );
    }

    public function sendTaskMoved(Task $task, User $user, string $columnTitle): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_moved_' . now()->timestamp,
            message: $this->taskMovedMessage($task, $columnTitle),
            task: $task,
            allowRepeat: true,
        );
    }

    public function notifyTaskAssignees(Task $task): void
    {
        $task->loadMissing(['assignees', 'assignee', 'room', 'board', 'column']);

        if ($task->assignees->isNotEmpty()) {
            foreach ($task->assignees as $user) {
                $this->sendTaskAssigned($task, $user);
            }

            return;
        }

        if ($task->assignee) {
            $this->sendTaskAssigned($task, $task->assignee);
        }
    }

    public function notifyDeadlineSoon(Task $task, string $type): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendDeadlineSoon($task, $user, $type);
        }
    }

    public function notifyOverdue(Task $task): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendTaskOverdue($task, $user);
        }
    }

    public function notifyDeadlineChanged(Task $task): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendDeadlineChanged($task, $user);
        }
    }

    public function notifyNewComment(Task $task, User $author, string $comment): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendNewComment($task, $user, $author, $comment);
        }
    }

    public function notifyTaskMoved(Task $task, string $columnTitle): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendTaskMoved($task, $user, $columnTitle);
        }
    }

    protected function taskUsers(Task $task)
    {
        $task->loadMissing(['assignees', 'assignee']);

        if ($task->assignees->isNotEmpty()) {
            return $task->assignees;
        }

        return $task->assignee ? collect([$task->assignee]) : collect();
    }

    protected function sendToUser(
        User $user,
        string $type,
        string $message,
        ?Task $task = null,
        ?TaskRoom $room = null,
        bool $allowRepeat = false,
    ): void {
        if (! $user->telegram_id) {
            Log::warning('Task telegram notification skipped: user telegram_id is empty', [
                'user_id' => $user->id,
                'type' => $type,
                'task_id' => $task?->id,
                'room_id' => $room?->id,
            ]);

            return;
        }

        if (! $allowRepeat) {
            $alreadySent = TaskNotification::query()
                ->where('user_id', $user->id)
                ->where('type', $type)
                ->when($task, fn ($q) => $q->where('task_id', $task->id))
                ->when($room, fn ($q) => $q->where('task_room_id', $room->id))
                ->exists();

            if ($alreadySent) {
                return;
            }
        }

        $notification = TaskNotification::create([
            'task_id' => $task?->id,
            'task_room_id' => $room?->id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'pending',
        ]);

        try {
            $this->sendTelegram($user, $message);

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $notification->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::error('Task telegram notification failed', [
                'user_id' => $user->id,
                'task_id' => $task?->id,
                'room_id' => $room?->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendTelegram(User $user, string $message): void
    {
        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            throw new \RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            [
                'chat_id' => $user->telegram_id,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );

        if (! $response->successful()) {
            throw new \RuntimeException($response->body());
        }
    }

    protected function roomAddedMessage(TaskRoom $room): string
    {
        $url = route('page-tasks.room', $room);

        return implode("\n", [
            '👥 <b>Вас добавили в комнату</b>',
            '',
            '<b>Комната:</b> ' . e($room->title),
            $room->description ? '<b>Описание:</b> ' . e($room->description) : null,
            '',
            '<a href="' . e($url) . '">Открыть комнату</a>',
        ]);
    }

    protected function taskAssignedMessage(Task $task): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", array_filter([
            '📝 <b>Вам назначили задачу</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Комната:</b> ' . e($task->room?->title ?? 'Без комнаты'),
            $task->board ? '<b>Доска:</b> ' . e($task->board->title) : null,
            $task->column ? '<b>Колонка:</b> ' . e($task->column->title) : null,
            '<b>Приоритет:</b> ' . e($task->displayPriority()),
            '<b>Дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]));
    }

    protected function deadlineSoonMessage(Task $task): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", array_filter([
            '⏰ <b>Скоро дедлайн</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Комната:</b> ' . e($task->room?->title ?? 'Без комнаты'),
            '<b>Дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]));
    }

    protected function taskOverdueMessage(Task $task): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", array_filter([
            '🔥 <b>Задача просрочена</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Комната:</b> ' . e($task->room?->title ?? 'Без комнаты'),
            '<b>Дедлайн был:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]));
    }

    protected function deadlineChangedMessage(Task $task): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", array_filter([
            '🔁 <b>Дедлайн задачи изменен</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Новый дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]));
    }

    protected function newCommentMessage(Task $task, User $author, string $comment): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", [
            '💬 <b>Новый комментарий в задаче</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>От:</b> ' . e($author->name ?? 'Пользователь'),
            '',
            e(mb_strimwidth($comment, 0, 300, '...')),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]);
    }

    protected function taskMovedMessage(Task $task, string $columnTitle): string
    {
        $url = route('page-tasks.show', $task);

        return implode("\n", [
            '📌 <b>Задачу переместили</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Теперь в колонке:</b> ' . e($columnTitle),
            '',
            '<a href="' . e($url) . '">Открыть задачу</a>',
        ]);
    }
}
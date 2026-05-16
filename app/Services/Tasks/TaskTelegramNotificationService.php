<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskNotification;
use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Support\Collection;
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
            buttons: [
                [
                    [
                        'text' => 'Открыть пространство',
                        'url' => route('page-tasks.room', $room),
                    ],
                ],
            ],
            room: $room,
        );
    }

    public function notifyTaskAssignees(Task $task): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendTaskAssigned($task, $user);
        }
    }

    public function sendTaskAssigned(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_assigned',
            message: $this->taskAssignedMessage($task),
            buttons: $this->taskButtons($task),
            task: $task,
        );
    }

    public function notifyDeadlineSoon(Task $task, string $type): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendDeadlineSoon($task, $user, $type);
        }
    }

    public function sendDeadlineSoon(Task $task, User $user, string $type): void
    {
        $this->sendToUser(
            user: $user,
            type: $type,
            message: $this->deadlineSoonMessage($task),
            buttons: $this->taskButtons($task),
            task: $task,
        );
    }

    public function notifyOverdue(Task $task): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendTaskOverdue($task, $user);
        }
    }

    public function sendTaskOverdue(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_overdue',
            message: $this->taskOverdueMessage($task),
            buttons: $this->taskButtons($task),
            task: $task,
        );
    }

    public function notifyDeadlineChanged(Task $task): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendDeadlineChanged($task, $user);
        }
    }

    public function sendDeadlineChanged(Task $task, User $user): void
    {
        $this->sendToUser(
            user: $user,
            type: 'deadline_changed_' . now()->timestamp,
            message: $this->deadlineChangedMessage($task),
            buttons: $this->taskButtons($task),
            task: $task,
            allowRepeat: true,
        );
    }

    public function notifyNewComment(Task $task, User $author, string $comment): void
    {
        foreach ($this->taskUsers($task) as $user) {
            if ($user->id === $author->id) {
                continue;
            }

            $this->sendNewComment($task, $user, $author, $comment);
        }
    }

    public function sendNewComment(Task $task, User $user, User $author, string $comment): void
    {
        $this->sendToUser(
            user: $user,
            type: 'new_comment_' . now()->timestamp . '_' . $author->id,
            message: $this->newCommentMessage($task, $author, $comment),
            buttons: $this->taskButtons($task),
            task: $task,
            allowRepeat: true,
        );
    }

    public function notifyTaskMoved(Task $task, string $columnTitle): void
    {
        foreach ($this->taskUsers($task) as $user) {
            $this->sendTaskMoved($task, $user, $columnTitle);
        }
    }

    public function sendTaskMoved(Task $task, User $user, string $columnTitle): void
    {
        $this->sendToUser(
            user: $user,
            type: 'task_moved_' . now()->timestamp,
            message: $this->taskMovedMessage($task, $columnTitle),
            buttons: $this->taskButtons($task),
            task: $task,
            allowRepeat: true,
        );
    }

    protected function taskUsers(Task $task): Collection
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
        ?array $buttons = null,
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
                ->when($task, fn ($query) => $query->where('task_id', $task->id))
                ->when($room, fn ($query) => $query->where('task_room_id', $room->id))
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
            $this->sendTelegram($user, $message, $buttons);

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

    protected function sendTelegram(User $user, string $message, ?array $buttons = null): void
    {
        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            throw new \RuntimeException('Telegram bot token is not configured.');
        }

        $payload = [
            'chat_id' => $user->telegram_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($buttons) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $buttons,
            ];
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            $payload
        );

        if (! $response->successful()) {
            throw new \RuntimeException($response->body());
        }
    }

    protected function taskButtons(Task $task): array
    {
        return [
            [
                [
                    'text' => 'Открыть задачу',
                    'url' => route('page-tasks.show', $task),
                ],
            ],
        ];
    }

    protected function roomAddedMessage(TaskRoom $room): string
    {
        return implode("\n", array_filter([
            '👥 <b>Вас добавили в рабочее пространство</b>',
            '',
            '<b>Пространство:</b> ' . e($room->title),
            $room->description ? '<b>Описание:</b> ' . e($room->description) : null,
        ]));
    }

    protected function taskAssignedMessage(Task $task): string
    {
        $task->loadMissing(['room', 'board', 'column']);

        return implode("\n", array_filter([
            '📝 <b>Вам назначили задачу</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Пространство:</b> ' . e($task->room?->title ?? 'Без пространства'),
            $task->board ? '<b>Доска:</b> ' . e($task->board->title) : null,
            $task->column ? '<b>Колонка:</b> ' . e($task->column->title) : null,
            '<b>Приоритет:</b> ' . e($task->displayPriority()),
            '<b>Дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
        ]));
    }

    protected function deadlineSoonMessage(Task $task): string
    {
        return implode("\n", array_filter([
            '⏰ <b>Скоро дедлайн</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Пространство:</b> ' . e($task->room?->title ?? 'Без пространства'),
            '<b>Дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
        ]));
    }

    protected function taskOverdueMessage(Task $task): string
    {
        return implode("\n", array_filter([
            '🔥 <b>Задача просрочена</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Пространство:</b> ' . e($task->room?->title ?? 'Без пространства'),
            '<b>Дедлайн был:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
        ]));
    }

    protected function deadlineChangedMessage(Task $task): string
    {
        return implode("\n", array_filter([
            '🔁 <b>Дедлайн задачи изменен</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Новый дедлайн:</b> ' . e($task->deadline_at?->format('d.m.Y H:i') ?? 'Без срока'),
        ]));
    }

    protected function newCommentMessage(Task $task, User $author, string $comment): string
    {
        return implode("\n", [
            '💬 <b>Новый комментарий в задаче</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>От:</b> ' . e($author->name ?? 'Пользователь'),
            '',
            e(mb_strimwidth($comment, 0, 300, '...')),
        ]);
    }

    protected function taskMovedMessage(Task $task, string $columnTitle): string
    {
        return implode("\n", [
            '📌 <b>Задачу переместили</b>',
            '',
            '<b>Задача:</b> ' . e($task->title),
            '<b>Теперь в колонке:</b> ' . e($columnTitle),
        ]);
    }
}
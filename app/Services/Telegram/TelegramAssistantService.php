<?php

namespace App\Services\Telegram;

use App\Models\TelegramAssistantRequest;
use App\Models\TelegramAssistantRequestMessage;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TelegramAssistantService
{
    public function __construct(
        protected TelegramAssistantClassifier $classifier,
        protected TelegramBotService $bot,
    ) {}

    public function isActivated(array $message): bool
    {
        if (! config('services.telegram.assistant_enabled', true)) {
            return false;
        }

        return $this->classifier->isActivated(
            $message,
            (string) config('services.telegram.bot_username', ''),
        );
    }

    public function handle(TelegramMessage $message, array $payload, bool $force = false): ?TelegramAssistantRequest
    {
        if (! config('services.telegram.assistant_enabled', true)) {
            return null;
        }

        if (data_get($payload, 'from.is_bot') === true) {
            return null;
        }

        $text = trim((string) ($message->text ?: $message->caption ?: ''));

        if ($text === '') {
            return null;
        }

        $chatId = (string) ($message->chat?->telegram_chat_id ?? '');
        $topicId = $message->topic?->telegram_thread_id;
        $telegramUserId = $message->telegramUser?->telegram_user_id;

        if ($chatId === '') {
            return null;
        }

        $linkedUserId = $this->resolveLinkedUser($message);
        $replyToMessageId = data_get($payload, 'reply_to_message.message_id');

        $existingRootRequest = TelegramAssistantRequest::query()
            ->where('telegram_chat_id', $chatId)
            ->where('root_message_id', $message->id)
            ->first();

        if ($existingRootRequest) {
            return $existingRootRequest;
        }

        $request = $replyToMessageId
            ? TelegramAssistantRequest::query()
                ->where('telegram_chat_id', $chatId)
                ->where('last_bot_message_id', (string) $replyToMessageId)
                ->whereIn('status', ['awaiting_clarification', 'received'])
                ->latest('id')
                ->first()
            : null;

        if ($request) {
            $this->attachMessage($request, $message, 'follow_up');

            $metadata = is_array($request->metadata) ? $request->metadata : [];
            $metadata['follow_up_count'] = ((int) ($metadata['follow_up_count'] ?? 0)) + 1;
            $metadata['last_follow_up_text'] = $text;

            $request->forceFill([
                'status' => 'assigned',
                'linked_user_id' => $linkedUserId ?: $request->linked_user_id,
                'metadata' => $metadata,
            ])->save();

            $answer = 'Спасибо, уточнение получил. Я передал обращение ответственному сотруднику.';
            $botMessageId = $this->bot->sendMessage(
                $chatId,
                $answer,
                $topicId,
                (string) $message->message_id,
            );

            if ($botMessageId) {
                $request->forceFill(['last_bot_message_id' => (string) $botMessageId])->save();
            }

            $this->notifyResponsible($request, $text, $message);

            return $request;
        }

        if (! $force && ! $this->isActivated($payload)) {
            return null;
        }

        $classification = $this->classifier->classify($text);
        $question = $this->classifier->clarificationQuestion($classification['category']);
        $isPrivate = data_get($payload, 'chat.type') === 'private';

        $request = TelegramAssistantRequest::create([
            'telegram_chat_id' => $chatId,
            'telegram_topic_id' => $topicId,
            'telegram_user_id' => $telegramUserId,
            'linked_user_id' => $linkedUserId,
            'root_message_id' => $message->id,
            'category' => $classification['category'],
            'status' => $question ? 'awaiting_clarification' : 'received',
            'original_text' => $text,
            'is_sensitive' => $classification['sensitive'],
            'metadata' => [
                'confidence' => $classification['confidence'],
                'chat_type' => data_get($payload, 'chat.type'),
            ],
        ]);

        $this->attachMessage($request, $message, 'root');

        $categoryLabel = $this->classifier->label($classification['category']);
        $answer = $classification['sensitive'] && ! $isPrivate
            ? 'Я зафиксировал чувствительное обращение и передал его ответственному сотруднику. Подробности не буду обсуждать в общем чате.'
            : 'Я зафиксировал сообщение как «' . $categoryLabel . '».'
                . ($question ? "\n\n{$question}" : "\n\nЯ передам его ответственному сотруднику.");

        $botMessageId = $this->bot->sendMessage(
            $chatId,
            $answer,
            $topicId,
            (string) $message->message_id,
        );

        if ($botMessageId) {
            $request->forceFill(['last_bot_message_id' => (string) $botMessageId])->save();
        }

        $this->notifyResponsible($request, $text, $message);

        return $request;
    }

    protected function resolveLinkedUser(TelegramMessage $message): ?int
    {
        $telegramUser = $message->telegramUser;

        if (! $telegramUser) {
            return null;
        }

        if ($telegramUser->linked_user_id) {
            return (int) $telegramUser->linked_user_id;
        }

        $user = User::query()
            ->where('telegram_id', (string) $telegramUser->telegram_user_id)
            ->first();

        if ($user) {
            $telegramUser->forceFill(['linked_user_id' => $user->id])->save();

            return (int) $user->id;
        }

        return null;
    }

    protected function attachMessage(
        TelegramAssistantRequest $request,
        TelegramMessage $message,
        string $direction,
    ): void {
        TelegramAssistantRequestMessage::firstOrCreate(
            [
                'request_id' => $request->id,
                'telegram_message_id' => $message->id,
            ],
            [
                'direction' => $direction,
            ],
        );
    }

    protected function notifyResponsible(
        TelegramAssistantRequest $request,
        string $latestText,
        TelegramMessage $message,
    ): void {
        $staffChatId = (string) config('services.telegram.assistant_staff_chat_id', '');

        if ($staffChatId === '') {
            Log::warning('Telegram assistant responsible chat is not configured', [
                'request_id' => $request->id,
            ]);

            return;
        }

        $category = $this->classifier->label($request->category);
        $author = $message->telegramUser?->full_name
            ?: $message->telegramUser?->username
            ?: 'Неизвестный пользователь';

        $lines = [
            '📩 <b>Новое обращение Telegram</b>',
            '',
            '<b>Категория:</b> ' . e($category),
            '<b>Автор:</b> ' . e($author),
            '<b>Статус:</b> ' . e($request->status),
        ];

        if (! $request->is_sensitive) {
            $lines[] = '';
            $lines[] = '<blockquote>' . e($latestText) . '</blockquote>';
        } else {
            $lines[] = '';
            $lines[] = 'Подробности скрыты: чувствительная категория.';
        }

        $this->bot->sendMessage(
            $staffChatId,
            implode("\n", $lines),
            config('services.telegram.assistant_staff_thread_id') ?: null,
        );
    }
}

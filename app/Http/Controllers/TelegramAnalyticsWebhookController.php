<?php

namespace App\Http\Controllers;

use App\Models\TelegramAttachment;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramAnalyticsWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        if ($secret !== config('services.telegram.analytics_webhook_secret')) {
            abort(403);
        }

        $update = $request->all();

        $message = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? $update['edited_channel_post']
            ?? null;

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatData = $message['chat'] ?? null;

        if (! $chatData || ! isset($chatData['id'])) {
            return response()->json(['ok' => true]);
        }

        $telegramChatId = (string) $chatData['id'];
        $messageId = (string) ($message['message_id'] ?? '');

        if ($messageId === '') {
            return response()->json(['ok' => true]);
        }

        $chat = TelegramChat::updateOrCreate(
            [
                'telegram_chat_id' => $telegramChatId,
            ],
            [
                'title' => $chatData['title']
                    ?? $chatData['username']
                    ?? trim(($chatData['first_name'] ?? '') . ' ' . ($chatData['last_name'] ?? ''))
                    ?: 'Чат ' . $telegramChatId,

                'type' => $chatData['type'] ?? 'unknown',
                'is_enabled' => true,
            ]
        );

        $topic = $this->resolveTopic($chat, $message);
        $user = $this->resolveUser($message);

        $messageType = $this->detectMessageType($message);

        $telegramMessage = TelegramMessage::updateOrCreate(
            [
                'telegram_chat_id' => $chat->id,
                'message_id' => $messageId,
            ],
            [
                'telegram_topic_id' => $topic?->id,
                'telegram_user_id' => $user?->id,

                'message_type' => $messageType,

                'text' => $message['text'] ?? null,
                'caption' => $message['caption'] ?? null,

                'sent_at' => isset($message['date'])
                    ? Carbon::createFromTimestamp($message['date'])
                    : null,

                'edited_at' => isset($message['edit_date'])
                    ? Carbon::createFromTimestamp($message['edit_date'])
                    : null,

                'raw' => $update,
            ]
        );

        $this->saveAttachments($telegramMessage, $message);

        return response()->json(['ok' => true]);
    }

private function resolveTopic(TelegramChat $chat, array $message): ?TelegramTopic
{
    $threadId = $message['message_thread_id'] ?? null;

    // сообщение не из темы форума
    if (empty($threadId)) {
        return null;
    }

    $title = null;

    if (
        isset($message['forum_topic_created']) &&
        isset($message['forum_topic_created']['name'])
    ) {
        $title = $message['forum_topic_created']['name'];
    }

    return TelegramTopic::updateOrCreate(
        [
            'telegram_chat_id' => $chat->id,
            'telegram_thread_id' => (string)$threadId,
        ],
        [
            'title' => $title ?: 'Тема #' . $threadId,
            'is_enabled' => true,
        ]
    );
}

    private function resolveUser(array $message): ?TelegramUser
    {
        $from = $message['from'] ?? $message['sender_chat'] ?? null;

        if (! $from || ! isset($from['id'])) {
            return null;
        }

        return TelegramUser::updateOrCreate(
            [
                'telegram_user_id' => (string) $from['id'],
            ],
            [
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'full_name' => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null,
                'last_seen_at' => now(),
            ]
        );
    }

    private function detectMessageType(array $message): string
    {
        return match (true) {
            isset($message['text']) => 'text',
            isset($message['photo']) => 'photo',
            isset($message['video']) => 'video',
            isset($message['document']) => 'document',
            isset($message['audio']) => 'audio',
            isset($message['voice']) => 'voice',
            isset($message['sticker']) => 'sticker',
            isset($message['animation']) => 'animation',
            isset($message['location']) => 'location',
            isset($message['contact']) => 'contact',
            isset($message['poll']) => 'poll',
            isset($message['forum_topic_created']) => 'forum_topic_created',
            default => 'unknown',
        };
    }

    private function saveAttachments(TelegramMessage $telegramMessage, array $message): void
    {
        if (isset($message['photo'])) {
            $photo = collect($message['photo'])->sortByDesc('file_size')->first();

            if ($photo) {
                $this->saveAttachment($telegramMessage, 'photo', $photo);
            }
        }

        foreach (['video', 'document', 'audio', 'voice', 'animation', 'sticker'] as $type) {
            if (isset($message[$type]) && is_array($message[$type])) {
                $this->saveAttachment($telegramMessage, $type, $message[$type]);
            }
        }
    }

    private function saveAttachment(TelegramMessage $telegramMessage, string $type, array $file): void
    {
        $fileId = $file['file_id'] ?? null;

        if (! $fileId) {
            return;
        }

        TelegramAttachment::updateOrCreate(
            [
                'telegram_message_id' => $telegramMessage->id,
                'file_id' => $fileId,
            ],
            [
                'type' => $type,
                'file_unique_id' => $file['file_unique_id'] ?? null,
                'mime_type' => $file['mime_type'] ?? null,
                'file_name' => $file['file_name'] ?? null,
                'file_size' => $file['file_size'] ?? null,
            ]
        );
    }
}
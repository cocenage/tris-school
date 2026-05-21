<?php

namespace App\Services\Telegram;

use App\Models\TelegramAttachment;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use App\Models\TelegramUser;
use Carbon\Carbon;

class TelegramUpdateIngestService
{
    public function ingest(array $update): ?TelegramMessage
    {
        $message = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? null;

        if (!$message) {
            return null;
        }

        $chatData = $message['chat'] ?? [];
        $fromData = $message['from'] ?? null;

        $chatId = (string) ($chatData['id'] ?? '');

        if ($chatId === '') {
            return null;
        }

        $chat = TelegramChat::updateOrCreate(
            ['telegram_chat_id' => $chatId],
            [
                'title' => $chatData['title'] ?? null,
                'type' => $chatData['type'] ?? null,
                'is_enabled' => true,
            ]
        );

        $topic = null;

        if (isset($message['message_thread_id'])) {
            $topic = TelegramTopic::firstOrCreate(
                [
                    'telegram_chat_id' => $chat->id,
                    'telegram_thread_id' => (string) $message['message_thread_id'],
                ],
                [
                    'title' => null,
                    'purpose' => null,
                    'is_enabled' => true,
                ]
            );
        }

        $telegramUser = null;

        if ($fromData && isset($fromData['id'])) {
            $firstName = $fromData['first_name'] ?? null;
            $lastName = $fromData['last_name'] ?? null;
            $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

            $telegramUser = TelegramUser::updateOrCreate(
                ['telegram_user_id' => (string) $fromData['id']],
                [
                    'username' => $fromData['username'] ?? null,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'full_name' => $fullName !== '' ? $fullName : null,
                    'last_seen_at' => now(),
                ]
            );
        }

        $messageModel = TelegramMessage::updateOrCreate(
            [
                'telegram_chat_id' => $chat->id,
                'message_id' => (string) ($message['message_id'] ?? ''),
            ],
            [
                'telegram_topic_id' => $topic?->id,
                'telegram_user_id' => $telegramUser?->id,

                'message_type' => $this->detectType($message),

                'text' => $message['text'] ?? null,
                'caption' => $message['caption'] ?? null,

                'sent_at' => isset($message['date'])
                    ? Carbon::createFromTimestamp($message['date'])
                    : now(),

                'edited_at' => isset($message['edit_date'])
                    ? Carbon::createFromTimestamp($message['edit_date'])
                    : null,

                'raw' => $update,
            ]
        );

        $this->syncAttachments($messageModel, $message);

app(\App\Services\Telegram\TelegramInstructionAutoReplyService::class)
    ->handle($messageModel->fresh(['chat', 'topic', 'telegramUser', 'attachments']));

return $messageModel;
        
    }

    protected function detectType(array $message): string
    {
        return match (true) {
            isset($message['text']) => 'text',
            isset($message['photo']) => 'photo',
            isset($message['document']) => 'document',
            isset($message['voice']) => 'voice',
            isset($message['video']) => 'video',
            isset($message['sticker']) => 'sticker',
            default => 'unknown',
        };
    }

    protected function syncAttachments(TelegramMessage $messageModel, array $message): void
    {
        $messageModel->attachments()->delete();

        if (isset($message['photo'])) {
            $photo = collect($message['photo'])->sortByDesc('file_size')->first();

            if ($photo) {
                TelegramAttachment::create([
                    'telegram_message_id' => $messageModel->id,
                    'type' => 'photo',
                    'file_id' => $photo['file_id'],
                    'file_unique_id' => $photo['file_unique_id'] ?? null,
                    'file_size' => $photo['file_size'] ?? null,
                ]);
            }
        }

        foreach (['document', 'voice', 'video', 'sticker'] as $type) {
            if (!isset($message[$type])) {
                continue;
            }

            $file = $message[$type];

            TelegramAttachment::create([
                'telegram_message_id' => $messageModel->id,
                'type' => $type,
                'file_id' => $file['file_id'] ?? '',
                'file_unique_id' => $file['file_unique_id'] ?? null,
                'mime_type' => $file['mime_type'] ?? null,
                'file_name' => $file['file_name'] ?? null,
                'file_size' => $file['file_size'] ?? null,
            ]);
        }
    }
}
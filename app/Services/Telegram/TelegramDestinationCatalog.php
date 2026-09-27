<?php

namespace App\Services\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramTopic;

class TelegramDestinationCatalog
{
    /** @return array<int, string> */
    public function chatOptions(?int $preserveChatId = null): array
    {
        $chats = TelegramChat::query()
            ->where(function ($query) use ($preserveChatId): void {
                $query->where('is_enabled', true)
                    ->whereNotNull('telegram_chat_id')
                    ->where('telegram_chat_id', '!=', '');

                if ($preserveChatId !== null) {
                    $query->orWhere('id', $preserveChatId);
                }
            })
            ->orderBy('title')
            ->get(['id', 'title', 'telegram_chat_id', 'is_enabled']);

        return $chats->mapWithKeys(fn (TelegramChat $chat): array => [
            $chat->getKey() => $this->chatLabel($chat),
        ])->all();
    }

    /** @return array<int, string> */
    public function topicOptions(?int $chatRecordId, ?int $preserveTopicId = null): array
    {
        if ($chatRecordId === null) {
            return [];
        }

        $chat = TelegramChat::query()->find($chatRecordId);

        if ($chat === null) {
            return [];
        }

        $topics = TelegramTopic::query()
            ->where('telegram_chat_id', $chatRecordId)
            ->where(function ($query) use ($chat, $preserveTopicId): void {
                if ($chat->is_enabled && filled($chat->telegram_chat_id)) {
                    $query->where('is_enabled', true)
                        ->whereNotNull('telegram_thread_id')
                        ->where('telegram_thread_id', '!=', '');
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($preserveTopicId !== null) {
                    $query->orWhere('id', $preserveTopicId);
                }
            })
            ->orderBy('telegram_thread_id')
            ->get(['id', 'telegram_chat_id', 'telegram_thread_id', 'title', 'is_enabled']);

        return $topics->mapWithKeys(fn (TelegramTopic $topic): array => [
            $topic->getKey() => $this->topicLabel($topic),
        ])->all();
    }

    public function chatLabel(TelegramChat $chat): string
    {
        $title = trim((string) $chat->title) ?: 'Telegram chat';
        $status = $chat->is_enabled ? '' : ' · отключён (текущий адресат)';

        return $title.' · '.$chat->telegram_chat_id.$status;
    }

    public function topicLabel(TelegramTopic $topic): string
    {
        $title = trim((string) $topic->title) ?: 'Тема';
        $status = $topic->is_enabled ? '' : ' · отключена (текущий адресат)';

        return $title.' · thread '.$topic->telegram_thread_id.$status;
    }
}
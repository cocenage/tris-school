<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use Illuminate\Support\Collection;

class TelegramTopicTitleResolver
{
    public function isMeaningful(?string $title): bool
    {
        $title = trim((string) $title);

        return $title !== ''
            && preg_match('/^(?:Тема|Topic)\s*#?\s*\d+$/iu', $title) !== 1;
    }

    public function explicitTitle(array $message): ?string
    {
        foreach (['forum_topic_edited', 'forum_topic_created'] as $key) {
            $title = trim((string) data_get($message, "{$key}.name"));

            if ($this->isMeaningful($title)) {
                return $title;
            }
        }

        return null;
    }

    /**
     * @return array{status: 'resolved'|'unresolved'|'conflict', title: ?string, source: ?string}
     */
    public function resolve(TelegramTopic $topic): array
    {
        return $this->resolveMany(collect([$topic]))[$topic->id];
    }

    /**
     * @param  Collection<int, TelegramTopic>  $topics
     * @return array<int, array{status: 'resolved'|'unresolved'|'conflict', title: ?string, source: ?string}>
     */
    public function resolveMany(Collection $topics): array
    {
        $topics->each->loadMissing('chat');

        $topicsById = $topics->keyBy('id');
        $directByTopic = [];
        $replyByTopic = [];

        foreach ($topicsById as $topicId => $topic) {
            $directByTopic[$topicId] = collect();
            $replyByTopic[$topicId] = collect();
        }

        TelegramMessage::query()
            ->whereIn('telegram_topic_id', $topicsById->keys())
            ->select(['id', 'message_id', 'sent_at', 'created_at', 'raw'])
            ->addSelect('telegram_topic_id')
            ->orderBy('telegram_topic_id')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->cursor()
            ->each(function (TelegramMessage $message) use ($topicsById, &$directByTopic, &$replyByTopic): void {
                $topic = $topicsById->get($message->telegram_topic_id);

                if (! $topic) {
                    return;
                }

                $payload = $this->messagePayload($message->raw);

                if ($payload === null || ! $this->matchesTopic($payload, $topic, false)) {
                    return;
                }

                $this->appendTitleEvents($directByTopic[$topic->id], $payload, $message, 'direct');

                $replyMessage = data_get($payload, 'reply_to_message');

                if (! is_array($replyMessage)
                    || ! $this->matchesTopic($replyMessage, $topic, true, $payload)) {
                    return;
                }

                $this->appendTitleEvents($replyByTopic[$topic->id], $replyMessage, $message, 'reply');
            });

        return $topicsById
            ->mapWithKeys(fn (TelegramTopic $topic): array => [
                $topic->id => $this->selectLatest(
                    $directByTopic[$topic->id]->isNotEmpty()
                        ? $directByTopic[$topic->id]
                        : $replyByTopic[$topic->id],
                ),
            ])
            ->all();
    }

    private function messagePayload(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return null;
        }

        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                return $raw[$key];
            }
        }

        return null;
    }

    private function matchesTopic(
        array $message,
        TelegramTopic $topic,
        bool $requireServiceMessageId,
        ?array $outerMessage = null,
    ): bool {
        $expectedChatId = (string) $topic->chat?->telegram_chat_id;
        $expectedThreadId = (string) $topic->telegram_thread_id;
        $chatId = data_get($message, 'chat.id');
        $threadId = $message['message_thread_id'] ?? null;

        if ($chatId !== null && (string) $chatId !== $expectedChatId) {
            return false;
        }

        if ($threadId !== null && (string) $threadId !== $expectedThreadId) {
            return false;
        }

        if ($outerMessage !== null) {
            $outerChatId = data_get($outerMessage, 'chat.id');
            $outerThreadId = $outerMessage['message_thread_id'] ?? null;

            if ($outerChatId !== null && (string) $outerChatId !== $expectedChatId) {
                return false;
            }

            if ($outerThreadId === null || (string) $outerThreadId !== $expectedThreadId) {
                return false;
            }
        }

        if ($requireServiceMessageId
            && (! isset($message['message_id']) || (string) $message['message_id'] !== $expectedThreadId)) {
            return false;
        }

        return true;
    }

    private function appendTitleEvents(
        Collection $events,
        array $serviceMessage,
        TelegramMessage $storedMessage,
        string $source,
    ): void {
        foreach (['forum_topic_created', 'forum_topic_edited'] as $type) {
            $title = trim((string) data_get($serviceMessage, "{$type}.name"));

            if (! $this->isMeaningful($title)) {
                continue;
            }

            $timestamp = $serviceMessage['date']
                ?? $storedMessage->sent_at?->getTimestamp()
                ?? $storedMessage->created_at?->getTimestamp()
                ?? 0;

            $events->push([
                'title' => $title,
                'source' => $source,
                'timestamp' => (int) $timestamp,
                'message_id' => (string) ($serviceMessage['message_id'] ?? $storedMessage->message_id),
                'type' => $type,
                'stored_id' => (int) $storedMessage->id,
            ]);
        }
    }

    /**
     * @return array{status: 'resolved'|'unresolved'|'conflict', title: ?string, source: ?string}
     */
    private function selectLatest(Collection $events): array
    {
        if ($events->isEmpty()) {
            return ['status' => 'unresolved', 'title' => null, 'source' => null];
        }

        $conflict = $events
            ->groupBy(fn (array $event): string => implode('|', [
                $event['timestamp'],
                $event['message_id'],
                $event['type'],
            ]))
            ->contains(fn (Collection $sameEvent): bool => $sameEvent->pluck('title')->unique()->count() > 1);

        if ($conflict) {
            return ['status' => 'conflict', 'title' => null, 'source' => null];
        }

        $latest = $events
            ->sortBy(fn (array $event): string => sprintf(
                '%020d|%020d|%020d',
                $event['timestamp'],
                ctype_digit($event['message_id']) ? (int) $event['message_id'] : 0,
                $event['stored_id'],
            ))
            ->last();

        return [
            'status' => 'resolved',
            'title' => $latest['title'],
            'source' => $latest['source'],
        ];
    }
}

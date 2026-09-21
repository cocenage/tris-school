<?php

namespace App\Services\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use Illuminate\Support\Str;

class TelegramTopicPresenter
{
    public function title(TelegramTopic $topic): string
    {
        $stored = trim((string) $topic->title);

        if (! $this->isPlaceholderTitle($stored)) {
            return $stored;
        }

        return $this->titleFromLoadedRaw($topic)
            ?? 'Topic #'.$topic->telegram_thread_id;
    }

    public function hasHumanTitle(TelegramTopic $topic): bool
    {
        return ! $this->isPlaceholderTitle(trim((string) $topic->title))
            || $this->titleFromLoadedRaw($topic) !== null;
    }

    public function contextPreview(TelegramTopic $topic): ?string
    {
        if ($this->hasHumanTitle($topic)) {
            return null;
        }

        $messages = $topic->relationLoaded('recentMessages')
            ? $topic->recentMessages
            : $topic->recentMessages()->get();

        $snippets = $messages
            ->map(fn (TelegramMessage $message): string => Str::squish((string) ($message->text ?: $message->caption)))
            ->filter()
            ->unique()
            ->take(2)
            ->map(fn (string $text): string => Str::limit($text, 110))
            ->values();

        return $snippets->isEmpty() ? null : $snippets->implode(' · ');
    }

    public function telegramUrl(TelegramTopic $topic): ?string
    {
        $chatId = (string) $topic->chat?->telegram_chat_id;
        $threadId = trim((string) $topic->telegram_thread_id);

        if (! preg_match('/^-100(\d+)$/', $chatId, $matches) || ! ctype_digit($threadId) || (int) $threadId < 1) {
            return null;
        }

        return "https://t.me/c/{$matches[1]}/{$threadId}";
    }

    public function isDutyTopic(TelegramTopic $topic): bool
    {
        $chatId = (string) $topic->chat?->telegram_chat_id;
        $threadId = (string) $topic->telegram_thread_id;

        foreach (config('services.telegram.digest_districts', []) as $route) {
            if ((string) ($route['chat_id'] ?? '') === $chatId
                && (string) ($route['duty_thread_id'] ?? '') === $threadId) {
                return true;
            }
        }

        return (string) config('services.telegram.evening_intelligence_central_chat_id') === $chatId
            && (string) config('services.telegram.evening_intelligence_central_thread_id') === $threadId;
    }

    public function isServiceTopic(TelegramTopic $topic): bool
    {
        return $this->isDutyTopic($topic) || filled($topic->purpose);
    }

    public function roleLabel(TelegramTopic $topic): string
    {
        return match (true) {
            $this->isDutyTopic($topic) => 'Дежурный',
            filled($topic->purpose) => 'Служебный',
            default => 'Квартирный',
        };
    }

    public function mappingLabel(TelegramTopic $topic): string
    {
        if ($this->isServiceTopic($topic)) {
            return $topic->apartment_id === null
                ? 'Квартира не требуется'
                : 'Проверьте: служебный topic с квартирой';
        }

        return $topic->apartment_id === null
            ? 'Квартира не назначена'
            : 'Квартира назначена';
    }

    public function mappingColor(TelegramTopic $topic): string
    {
        return match (true) {
            $this->isServiceTopic($topic) && $topic->apartment_id !== null => 'warning',
            $this->isServiceTopic($topic) => 'info',
            $topic->apartment_id !== null => 'success',
            default => 'danger',
        };
    }

    public function chatLabel(TelegramChat $chat): string
    {
        foreach (config('services.telegram.digest_districts', []) as $route) {
            if ((string) ($route['chat_id'] ?? '') !== (string) $chat->telegram_chat_id) {
                continue;
            }

            return (string) ($route['label'] ?? $chat->title ?: 'Telegram chat');
        }

        return trim((string) $chat->title) ?: 'Telegram chat';
    }

    public function chatOptions(): array
    {
        return TelegramChat::query()
            ->whereHas('topics')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (TelegramChat $chat): array => [$chat->id => $this->chatLabel($chat)])
            ->all();
    }

    private function isPlaceholderTitle(string $title): bool
    {
        return $title === '' || preg_match('/^(?:Тема|Topic)\s*#?\d+$/iu', $title) === 1;
    }

    private function titleFromLoadedRaw(TelegramTopic $topic): ?string
    {
        if (! $topic->relationLoaded('recentMessages')) {
            return null;
        }

        foreach ($topic->recentMessages as $message) {
            $raw = $message->raw;

            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }

            if (! is_array($raw)) {
                continue;
            }

            $payload = $raw['message']
                ?? $raw['edited_message']
                ?? $raw['channel_post']
                ?? $raw['edited_channel_post']
                ?? [];
            $title = trim((string) data_get($payload, 'forum_topic_created.name'));

            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }
}

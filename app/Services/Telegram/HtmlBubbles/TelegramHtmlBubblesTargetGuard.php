<?php

namespace App\Services\Telegram\HtmlBubbles;

class TelegramHtmlBubblesTargetGuard
{
    public function configuredChatId(): ?string
    {
        $chatId = trim((string) config('services.telegram.htmlbubbles.test_chat_id', ''));

        return $chatId !== '' && is_numeric($chatId) ? $chatId : null;
    }

    public function configuredThreadId(): ?int
    {
        $threadId = config('services.telegram.htmlbubbles.test_thread_id');

        if ($threadId === null || $threadId === '') {
            return null;
        }

        return filter_var($threadId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    public function hasSafeConfiguredTarget(): bool
    {
        $chatId = $this->configuredChatId();

        return $this->threadConfigIsValid()
            && $chatId !== null
            && ! $this->isKnownOperationalChat($chatId);
    }

    public function allowsConfiguredTarget(?string $requestedChatId, ?string $requestedThreadId): bool
    {
        $configuredChatId = $this->configuredChatId();
        $requestedChatId = $requestedChatId !== null ? trim($requestedChatId) : null;

        if (! $this->hasSafeConfiguredTarget()
            || $configuredChatId === null
            || $requestedChatId === null
            || ! hash_equals($configuredChatId, $requestedChatId)
        ) {
            return false;
        }

        $configuredThreadId = $this->configuredThreadId();
        $requestedThreadId = $requestedThreadId !== null ? trim($requestedThreadId) : null;

        if ($configuredThreadId === null) {
            return $requestedThreadId === null || $requestedThreadId === '';
        }

        return $requestedThreadId === null || $requestedThreadId === ''
            || (string) $configuredThreadId === $requestedThreadId;
    }

    public function isConfiguredTestChat(?string $chatId): bool
    {
        $configuredChatId = $this->configuredChatId();

        return $this->hasSafeConfiguredTarget()
            && $configuredChatId !== null
            && $chatId !== null
            && hash_equals($configuredChatId, trim($chatId));
    }

    public function messageCacheKey(string $chatId, int $messageId): string
    {
        return 'telegram:htmlbubbles:test-message:'.hash('sha256', $chatId.':'.$messageId);
    }

    public function isKnownOperationalChat(string $chatId): bool
    {
        foreach ([config('services.telegram'), config('services.staff_forms')] as $values) {
            if (in_array($chatId, $this->collectChatIds($values), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function collectChatIds(mixed $value, string $key = ''): array
    {
        if (is_array($value)) {
            if ($this->isChatRouteKey($key)) {
                return $this->extractChatIds($value);
            }

            $ids = [];

            foreach ($value as $childKey => $childValue) {
                $ids = [...$ids, ...$this->collectChatIds($childValue, (string) $childKey)];
            }

            return array_values(array_unique($ids));
        }

        if (! $this->isChatRouteKey($key)) {
            return [];
        }

        return $this->extractChatIds($value);
    }

    private function isChatRouteKey(string $key): bool
    {
        $key = strtolower($key);

        return ! str_contains($key, 'test')
            && (str_contains($key, 'chat') || str_contains($key, 'target'));
    }

    /** @return list<string> */
    private function extractChatIds(mixed $value): array
    {
        if (is_array($value)) {
            $ids = [];

            foreach ($value as $childValue) {
                $ids = [...$ids, ...$this->extractChatIds($childValue)];
            }

            return array_values(array_unique($ids));
        }

        if (! is_scalar($value)) {
            return [];
        }

        preg_match_all('/-?\d+/', (string) $value, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function threadConfigIsValid(): bool
    {
        $threadId = config('services.telegram.htmlbubbles.test_thread_id');

        return $threadId === null
            || trim((string) $threadId) === ''
            || $this->configuredThreadId() !== null;
    }
}

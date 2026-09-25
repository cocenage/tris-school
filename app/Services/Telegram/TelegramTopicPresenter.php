<?php

namespace App\Services\Telegram;

use App\Models\Apartment;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TelegramTopicPresenter
{
    public function __construct(
        private readonly TelegramTopicTitleResolver $titleResolver,
    ) {}

    public function title(TelegramTopic $topic): string
    {
        $stored = trim((string) $topic->title);

        if ($this->titleResolver->isMeaningful($stored)) {
            return $stored;
        }

        return 'Topic #'.$topic->telegram_thread_id;
    }

    public function hasHumanTitle(TelegramTopic $topic): bool
    {
        return $this->titleResolver->isMeaningful($topic->title);
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

        return in_array([$chatId, $threadId], $this->serviceTopicPairs(), true);
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

    /** @return array<int, string> */
    public function apartmentOptions(): array
    {
        return Apartment::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'address'])
            ->mapWithKeys(function (Apartment $apartment): array {
                $details = collect([$apartment->code, $apartment->address])
                    ->filter(fn (mixed $value): bool => filled($value))
                    ->unique()
                    ->implode(' · ');
                $label = trim((string) $apartment->name);

                if ($details !== '' && ! str_contains($label, $details)) {
                    $label .= ' — '.$details;
                }

                return [$apartment->getKey() => $label];
            })
            ->all();
    }

    /** Keep the UI's candidate filter aligned with the existing service-topic rules. */
    public function scopeUnmappedApartmentCandidates(Builder $query): Builder
    {
        $query
            ->whereNull('apartment_id')
            ->where(fn (Builder $purpose): Builder => $purpose
                ->whereNull('purpose')
                ->orWhereRaw("TRIM(purpose) = ''"));

        foreach ($this->serviceTopicPairs() as [$chatId, $threadId]) {
            if ($chatId === '' || $threadId === '') {
                continue;
            }

            $query->where(function (Builder $candidate) use ($chatId, $threadId): void {
                $candidate
                    ->where('telegram_thread_id', '!=', $threadId)
                    ->orWhereDoesntHave('chat', fn (Builder $chat): Builder => $chat
                        ->where('telegram_chat_id', $chatId));
            });
        }

        return $query;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function serviceTopicPairs(): array
    {
        return collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $route): array => [
                (string) (is_array($route) ? ($route['chat_id'] ?? '') : ''),
                (string) (is_array($route) ? ($route['duty_thread_id'] ?? '') : ''),
            ])
            ->push([
                (string) config('services.telegram.evening_intelligence_central_chat_id'),
                (string) config('services.telegram.evening_intelligence_central_thread_id'),
            ])
            ->unique(fn (array $pair): string => implode('|', $pair))
            ->values()
            ->all();
    }
}

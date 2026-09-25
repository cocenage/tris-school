<?php

namespace Tests\Support;

use App\Models\Apartment;
use App\Models\TelegramOperationalEvent;
use App\Services\Telegram\TelegramOperationalEventObserver;

class TelegramApartmentHandoffFixtures
{
    public static function apartment(string $name): Apartment
    {
        return Apartment::query()->create(['name' => $name]);
    }

    /** @return array{0: \App\Models\TelegramMessage, 1: TelegramOperationalEvent} */
    public static function observe(
        string $text,
        Apartment $apartment,
        string $chatId = '-1001',
        string $threadId = '11',
        string $messageId = '1',
        bool $mapTopic = true,
    ): array {
        $message = TelegramOperationalTestDatabase::message(
            $text,
            messageId: $messageId,
            chatId: $chatId,
            threadId: $threadId,
        );

        if ($mapTopic) {
            $message->topic->update(['apartment_id' => $apartment->id]);
        }

        app(TelegramOperationalEventObserver::class)->observe(
            $message->fresh(['chat', 'topic', 'telegramUser', 'attachments'])
        );

        $event = TelegramOperationalEvent::query()
            ->where('root_message_id', $message->id)
            ->firstOrFail();

        return [$message, $event];
    }
}

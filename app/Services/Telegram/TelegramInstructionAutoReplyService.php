<?php

namespace App\Services\Telegram;

use App\Models\TelegramMessage;
use App\Services\Telegram\Knowledge\InstructionTelegramSearchService;
use Illuminate\Support\Facades\Cache;

class TelegramInstructionAutoReplyService
{
    public function __construct(
        protected InstructionTelegramSearchService $searchService,
        protected TelegramBotService $botService,
    ) {}

    public function handle(TelegramMessage $message): void
    {
        if ($message->message_type !== 'text') {
            return;
        }

        if (! filled($message->text)) {
            return;
        }

        $instruction = $this->searchService->findForText($message->text);

        if (! $instruction) {
            return;
        }

        $cacheKey = 'telegram_instruction_reply:' .
            $message->telegram_chat_id . ':' .
            ($message->telegram_topic_id ?: 'no_topic') . ':' .
            $instruction->id;

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addMinutes(10));

        $url = route('page-home.instructions.single', $instruction->slug);

        $text = "Похоже, вам нужна инструкция:\n\n"
            . "📌 <b>" . e($instruction->title) . "</b>\n"
            . e($instruction->short_description ?: '') . "\n\n"
            . "Открыть: {$url}";

        $this->botService->sendMessage(
            chatId: $message->chat->telegram_chat_id,
            text: $text,
            threadId: $message->topic?->telegram_thread_id,
        );
    }
}
<?php

namespace App\Console\Commands;

use App\Models\TelegramTopic;
use App\Models\TelegramMessage;
use Illuminate\Console\Command;

class RegisterMobilityTopicsCommand extends Command
{
    protected $signature = 'mobility:register-topics {tag=#mobility}';

    protected $description = 'Register Telegram forum topics for mobility digest by hashtag';

    public function handle(): int
    {
        $tag = $this->argument('tag');

        $messages = TelegramMessage::query()
            ->where('text', $tag)
            ->latest()
            ->limit(200)
            ->get();

        if ($messages->isEmpty()) {
            $this->warn("No messages found with tag: {$tag}");

            return self::SUCCESS;
        }

        $registered = 0;

        foreach ($messages as $message) {
            $raw = json_decode($message->raw, true);

            $chatId = $raw['message']['chat']['id'] ?? null;
            $threadId = $raw['message']['message_thread_id'] ?? null;
            $topicName = $raw['message']['reply_to_message']['forum_topic_created']['name'] ?? null;

            if (! $chatId || ! $threadId) {
                continue;
            }

            $topic = TelegramTopic::query()
                ->whereHas('chat', function ($query) use ($chatId) {
                    $query->where('telegram_chat_id', (string) $chatId);
                })
                ->where('telegram_thread_id', (string) $threadId)
                ->first();

            if (! $topic) {
                $this->warn("Topic not found in DB: {$chatId}:{$threadId}");

                continue;
            }

            $topic->update([
                'purpose' => 'mobility',
                'is_enabled' => true,
                'title' => $topicName ?: $topic->title,
            ]);

            $registered++;

            $this->line("Registered: {$chatId}:{$threadId} — {$topic->title}");
        }

        $this->info("Registered mobility topics: {$registered}");

        return self::SUCCESS;
    }
}
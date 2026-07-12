<?php

namespace App\Console\Commands;

use App\Models\MobilityAlertMessage;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilityDeleteMessagesCommand extends Command
{
    protected $signature = 'mobility:delete-messages
        {--type=worker_digest}
        {--today}
        {--date=}
        {--dry-run}';

    protected $description = 'Delete sent mobility Telegram messages';

    public function handle(): int
    {
        $query = MobilityAlertMessage::query()
            ->whereNull('deleted_at')
            ->where('message_type', $this->option('type'));

        if ($this->option('today')) {
            $query->whereDate('sent_at', now()->toDateString());
        }

        if ($this->option('date')) {
            $date = Carbon::parse($this->option('date'))->toDateString();

            $query->whereDate('sent_at', $date);
        }

        $messages = $query
            ->orderByDesc('sent_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No messages found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('===== DRY RUN DELETE MOBILITY MESSAGES =====');

            foreach ($messages as $message) {
                $this->line("chat_id={$message->chat_id} thread_id={$message->thread_id} message_id={$message->telegram_message_id} sent_at={$message->sent_at}");
            }

            $this->line('============================================');
            $this->line('');

            return self::SUCCESS;
        }

        $token = config('services.telegram.analytics_bot_token');

        if (! $token) {
            $this->error('Missing Telegram bot token.');

            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($messages as $message) {
            try {
                $response = Http::timeout(30)
                    ->retry(3, 2000)
                    ->withoutVerifying()
                    ->post("https://api.telegram.org/bot{$token}/deleteMessage", [
                        'chat_id' => $message->chat_id,
                        'message_id' => $message->telegram_message_id,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Mobility message delete connection failed', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'telegram_message_id' => $message->telegram_message_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Mobility message delete failed', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'telegram_message_id' => $message->telegram_message_id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $message->update([
                'deleted_at' => now(),
            ]);

            $deleted++;

            usleep(500000);
        }

        $this->info("Deleted messages: {$deleted}");

        return self::SUCCESS;
    }
}
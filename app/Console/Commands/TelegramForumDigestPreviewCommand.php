<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramForumDigestBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TelegramForumDigestPreviewCommand extends Command
{
    protected $signature = 'telegram:forum-digest-preview
        {--date= : Calendar date in the application timezone}
        {--json : Print the compact machine-readable contract}
        {--source-chat= : Limit source forums by external Telegram chat id}
        {--source-thread= : Limit source topics by external Telegram thread id}
        {--target-chat= : Preview route override, does not send anything}
        {--target-thread= : Preview route override, does not send anything}';

    protected $description = 'Preview a read-only evening digest of Telegram work forums and topics';

    public function handle(TelegramForumDigestBuilder $builder, TelegramDigestFormatter $formatter): int
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();

        $digest = $builder->build($date, [
            'source_chat' => $this->option('source-chat'),
            'source_thread' => $this->option('source-thread'),
            'target_chat' => $this->option('target-chat'),
            'target_thread' => $this->option('target-thread'),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($formatter->evening($digest));

        $route = $digest['route'] ?? ['status' => 'not_configured'];
        $this->newLine();
        $this->line('Маршрут: ' . (($route['status'] ?? null) === 'configured' ? 'настроен' : 'не настроен') . '.');
        if (($route['status'] ?? null) === 'configured') {
            $this->line(sprintf(
                'Цель: %s / %s%s',
                $route['target_chat_title'] ?: $route['target_chat_id'],
                $route['target_topic_title'] ?: $route['target_message_thread_id'],
                ($route['source'] ?? null) === 'cli' ? ' (переопределение CLI)' : '',
            ));
        }

        $this->line('Режим: только чтение. Telegram не отправляется, AI не вызывается, записи не изменяются.');

        return self::SUCCESS;
    }
}

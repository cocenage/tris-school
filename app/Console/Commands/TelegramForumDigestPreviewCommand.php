<?php

namespace App\Console\Commands;

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

    public function handle(TelegramForumDigestBuilder $builder): int
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

        $this->line('Вечерняя сводка по Telegram за ' . Carbon::parse($digest['date'])->format('d.m.Y'));
        $this->line(sprintf(
            'Рабочие форумы: %d, активные темы: %d, сообщений: %d, авторов: %d, вложений: %d.',
            $digest['totals']['forums'],
            $digest['totals']['active_topics'],
            $digest['totals']['messages'],
            $digest['totals']['authors'],
            $digest['totals']['attachments'],
        ));

        if (! empty($digest['attention_required'])) {
            $this->newLine();
            $this->line('Требует внимания:');
            foreach ($digest['attention_required'] as $item) {
                $this->line(sprintf(
                    '- %s / %s: %s (%s)',
                    $item['chat_id'],
                    $item['topic_title'],
                    $item['reason'],
                    $item['message_thread_id'],
                ));
            }
        }

        if (! empty($digest['positive_signals'])) {
            $this->newLine();
            $this->line('Положительные сигналы:');
            foreach ($digest['positive_signals'] as $item) {
                $this->line(sprintf('- %s / %s: %d', $item['chat_id'], $item['topic_title'], $item['count']));
            }
        }

        if (! empty($digest['forums'])) {
            $this->newLine();
            $this->line('По форумам:');
            foreach ($digest['forums'] as $forum) {
                $this->line(sprintf(
                    '- %s (%s): %d сообщений, %d активных тем, проблемных сигналов: %d.',
                    $forum['chat_title'] ?: 'Без названия',
                    $forum['chat_id'],
                    $forum['message_count'],
                    $forum['active_topics'],
                    $forum['problem_signals'],
                ));
            }
        }

        $route = $digest['route'];
        $this->newLine();
        $this->line('Маршрут: ' . ($route['status'] === 'configured' ? 'настроен' : 'не настроен') . '.');
        if ($route['status'] === 'configured') {
            $this->line(sprintf(
                'Цель: %s / %s%s',
                $route['target_chat_title'] ?: $route['target_chat_id'],
                $route['target_topic_title'] ?: $route['target_message_thread_id'],
                $route['source'] === 'cli' ? ' (переопределение CLI)' : '',
            ));
        }

        $this->line('Качество данных: ' . ($digest['data_quality']['analytics'] ?? 'unknown') . '.');
        $this->newLine();
        $this->line('Режим: только чтение. Telegram не отправляется, AI не вызывается, записи не изменяются.');

        return self::SUCCESS;
    }
}

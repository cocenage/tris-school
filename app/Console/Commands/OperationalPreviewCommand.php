<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalContextBuilder;
use App\Services\Telegram\TelegramDigestFormatter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class OperationalPreviewCommand extends Command
{
    protected $signature = 'operational:preview {--date=} {--json}';

    protected $description = 'Preview a read-only operational context without AI or Telegram delivery';

    public function handle(OperationalContextBuilder $builder, TelegramDigestFormatter $formatter): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), config('app.timezone', 'Europe/Rome'))->startOfDay()
            : now(config('app.timezone', 'Europe/Rome'))->startOfDay();

        $context = $builder->build($date);

        if ($this->option('json')) {
            $this->line(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($formatter->morning($context));
        $this->newLine();
        $this->line('Режим: только чтение. AI не вызывается, Telegram не отправляется, данные не изменяются.');

        return self::SUCCESS;
    }
}

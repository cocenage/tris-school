<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TelegramEveningIntelligencePreviewCommand extends Command
{
    protected $signature = 'telegram:evening-intelligence-preview
        {--date= : Required calendar date in the application timezone}
        {--district= : Optional configured district key}
        {--json : Emit the evidence-backed machine-readable preview}';

    protected $description = 'Preview read-only evening intelligence from the operational event ledger';

    public function handle(
        TelegramEveningIntelligenceBuilder $builder,
        TelegramDigestFormatter $formatter,
        TelegramDistrictRouteRegistry $districts,
    ): int {
        $date = $this->dateOption();

        if (! $date) {
            return self::FAILURE;
        }

        $route = null;
        if (filled($this->option('district'))) {
            $route = $districts->find((string) $this->option('district'));

            if ($route === null) {
                $this->error('District route is not configured or is incomplete.');

                return self::FAILURE;
            }
        }

        try {
            $preview = $builder->build($date, ['district' => $route]);
        } catch (Throwable) {
            $this->error('Operational event ledger is unavailable.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                $preview,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        foreach (explode("\n", $formatter->eveningIntelligence($preview)) as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->line('Предпросмотр: отправка в Telegram отключена.');

        return self::SUCCESS;
    }

    private function dateOption(): ?Carbon
    {
        if (! filled($this->option('date'))) {
            $this->error('Date is required and must use YYYY-MM-DD.');

            return null;
        }

        $value = (string) $this->option('date');

        try {
            $date = Carbon::createFromFormat(
                '!Y-m-d',
                $value,
                config('app.timezone', 'Europe/Rome'),
            );
        } catch (Throwable) {
            $this->error('Date must use YYYY-MM-DD.');

            return null;
        }

        if ($date->format('Y-m-d') !== $value) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return null;
        }

        return $date;
    }
}

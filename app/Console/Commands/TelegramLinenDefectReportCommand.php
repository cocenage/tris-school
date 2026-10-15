<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramLinenDefectReportBuilder;
use App\Services\Telegram\TelegramLinenDefectReportFormatter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramLinenDefectReportCommand extends Command
{
    protected $signature = 'telegram:linen-defects-report
        {--date= : Europe/Rome calendar date; defaults to today}
        {--dry-run : Show the report without Telegram actions}
        {--json : Emit report data and delivery results}';

    protected $description = 'Preview or safely deliver the daily OI linen-defect report';

    public function handle(
        TelegramLinenDefectReportBuilder $builder,
        TelegramLinenDefectReportFormatter $formatter,
        TelegramDistrictRouteRegistry $districts,
        TelegramBotService $bot,
    ): int {
        $timezone = config('app.timezone', 'Europe/Rome');
        $value = filled($this->option('date')) ? (string) $this->option('date') : now($timezone)->toDateString();
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            $this->error('Date must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $mode = (string) config('services.telegram.evening_intelligence_delivery_mode', 'centralized');
        if (! in_array($mode, ['centralized', 'per-district'], true)) {
            $this->error('Invalid evening intelligence delivery mode.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! (bool) config('services.telegram.evening_intelligence_delivery_enabled', false)) {
            $this->error('Evening intelligence delivery is disabled. Use --dry-run for preview.');

            return self::FAILURE;
        }

        try {
            $report = $builder->build($date);
        } catch (Throwable) {
            $this->error('Operational event ledger is unavailable.');

            return self::FAILURE;
        }

        $routes = $districts->sourceRoutes();
        if ($routes->isEmpty()) {
            $this->error('No complete district source routes are configured.');

            return self::FAILURE;
        }

        $centralChat = trim((string) config('services.telegram.evening_intelligence_central_chat_id'));
        $centralThread = trim((string) config('services.telegram.evening_intelligence_central_thread_id'));
        if (! $dryRun && $mode === 'centralized' && ($centralChat === '' || $centralThread === '')) {
            $this->error('Central evening intelligence destination is not configured.');

            return self::FAILURE;
        }

        $targets = $mode === 'centralized'
            ? [['key' => 'central', 'chat' => $centralChat, 'thread' => $centralThread, 'district' => null]]
            : $districts->routes()->map(fn (array $route): array => [
                'key' => $route['key'], 'chat' => (string) $route['chat_id'],
                'thread' => (string) $route['duty_thread_id'], 'district' => $route['key'],
            ])->all();
        $results = [];
        foreach ($targets as $target) {
            $text = $formatter->format($report, $target['district']);
            if ($dryRun) {
                $results[] = ['target' => $target['key'], 'status' => 'previewed'];
                if (! $this->option('json')) {
                    $this->line($text);
                    $this->newLine();
                    $this->line('Предпросмотр: отправка в Telegram отключена.');
                }

                continue;
            }

            $guard = 'telegram_linen_defects_v1:'.hash('sha256', implode('|', [
                $value, $mode, $target['key'], $target['chat'], $target['thread'],
            ]));
            try {
                $reserved = Cache::add($guard, 'attempted', now()->addDays(30));
                $previous = $reserved ? null : Cache::get($guard);
            } catch (Throwable) {
                $results[] = ['target' => $target['key'], 'status' => 'failed', 'reason' => 'idempotency_store_unavailable'];

                continue;
            }
            if (! $reserved) {
                $results[] = ['target' => $target['key'], 'status' => $previous === 'sent' ? 'skipped_duplicate' : 'blocked_uncertain'];

                continue;
            }
            try {
                $messageId = $mode === 'centralized'
                    ? $bot->sendMessage($target['chat'], $text, $target['thread'])
                    : $bot->sendAnalyticsMessage($target['chat'], $text, $target['thread']);
            } catch (Throwable) {
                $messageId = null;
            }
            if ($messageId !== null) {
                try {
                    Cache::put($guard, 'sent', now()->addDays(30));
                } catch (Throwable) {
                    // The reserved attempt prevents automatic retries after an uncertain cache update.
                }
            }
            $results[] = ['target' => $target['key'], 'status' => $messageId === null ? 'failed' : 'sent'];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['report' => $report, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (! $dryRun) {
            foreach ($results as $result) {
                $this->line($result['target'].': '.$result['status']);
            }
        }

        return collect($results)->contains(fn (array $result): bool => in_array($result['status'], ['failed', 'blocked_uncertain'], true))
            ? self::FAILURE : self::SUCCESS;
    }
}

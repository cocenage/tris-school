<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDigestBuildJournal;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramLinenDefectReportBuilder;
use App\Services\Telegram\TelegramLinenDefectReportFormatter;
use App\Services\Telegram\TelegramOutboundMonitor;
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

        $routes = $districts->sourceRoutes();
        $centralChat = trim((string) config('services.telegram.evening_intelligence_central_chat_id'));
        $centralThread = trim((string) config('services.telegram.evening_intelligence_central_thread_id'));
        $targets = $mode === 'centralized'
            ? [['key' => 'central', 'chat' => $centralChat, 'thread' => $centralThread, 'district' => null]]
            : $districts->routes()->map(fn (array $route): array => [
                'key' => $route['key'], 'chat' => (string) $route['chat_id'],
                'thread' => (string) $route['duty_thread_id'], 'district' => $route['key'],
            ])->all();

        $journal = app(TelegramDigestBuildJournal::class);
        $journalContexts = [];
        if (! $dryRun) {
            foreach ($targets as $target) {
                $context = [
                    'operation_key' => 'linen_defect:'.$value.':'.$target['key'],
                    'notification_type' => 'linen_defect',
                    'bot' => $mode === 'centralized' ? 'work' : 'analytics',
                    'chat_id' => $target['chat'],
                    'message_thread_id' => $target['thread'],
                    'district' => $target['district'],
                    'source_type' => 'TelegramLinenDefectReport',
                    'source_id' => $value,
                    'scheduled_for' => $value,
                ];
                $journalContexts[$target['key']] = $context;
                $journal->reserve($context);
            }
        }

        try {
            $report = $builder->build($date);
        } catch (Throwable) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'linen_source', 'failure_unclassified');
            }
            $this->error('Operational event ledger is unavailable.');

            return self::FAILURE;
        }

        $sourceMessages = (int) collect($report['districts'] ?? [])->sum('source_messages');
        $observedMessages = (int) collect($report['districts'] ?? [])->sum('observed_messages');
        $caseCount = count($report['cases'] ?? []);
        $sourceOutcome = $routes->isEmpty()
            ? 'failure_unclassified'
            : ($sourceMessages === 0
                ? 'no_input'
                : ($observedMessages < $sourceMessages
                    ? 'source_error'
                    : (($report['incomplete'] ?? false) ? 'failure_unclassified' : 'loaded')));
        foreach ($journalContexts as $context) {
            $journal->record($context, 'linen_source', $sourceOutcome, [
                'input_count' => $sourceMessages,
                'source_messages' => $sourceMessages,
                'observed_messages' => $observedMessages,
            ]);
            $journal->record($context, 'linen_filter', $caseCount > 0 ? 'complete' : 'no_significant_events', [
                'input_count' => $sourceMessages,
                'events_included' => $caseCount,
            ]);
        }

        if ($routes->isEmpty()) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'linen_format', 'no_message', ['message_present' => false]);
                $journal->record($context, 'linen_guard', 'skipped');
            }
            $this->error('No complete district source routes are configured.');

            return self::FAILURE;
        }

        if (! $dryRun && $mode === 'centralized' && ($centralChat === '' || $centralThread === '')) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'linen_format', 'no_message', ['message_present' => false]);
                $journal->record($context, 'linen_guard', 'skipped');
            }
            $this->error('Central evening intelligence destination is not configured.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($targets as $target) {
            $context = $journalContexts[$target['key']] ?? null;
            try {
                $text = $formatter->format($report, $target['district']);
            } catch (Throwable $exception) {
                if ($context !== null) {
                    $journal->record($context, 'linen_format', 'calculation_error');
                }

                throw $exception;
            }
            if ($context !== null) {
                $messagePresent = trim($text) !== '';
                $journal->record($context, 'linen_format', $messagePresent ? 'built' : 'no_message', [
                    'message_present' => $messagePresent,
                ]);
            }
            if ($dryRun) {
                $results[] = ['target' => $target['key'], 'status' => 'previewed'];
                if (! $this->option('json')) {
                    $this->line($text);
                    $this->newLine();
                    $this->line('Предпросмотр: отправка в Telegram отключена.');
                }

                continue;
            }

            if ($context !== null && trim($text) === '') {
                $results[] = ['target' => $target['key'], 'status' => 'no_message'];

                continue;
            }

            $guard = 'telegram_linen_defects_v1:'.hash('sha256', implode('|', [
                $value, $mode, $target['key'], $target['chat'], $target['thread'],
            ]));
            try {
                $reserved = Cache::add($guard, 'attempted', now()->addDays(30));
                $previous = $reserved ? null : Cache::get($guard);
            } catch (Throwable) {
                if ($context !== null) {
                    $journal->record($context, 'linen_guard', 'failure_unclassified');
                }
                $results[] = ['target' => $target['key'], 'status' => 'failed', 'reason' => 'idempotency_store_unavailable'];

                continue;
            }
            if (! $reserved) {
                if ($context !== null) {
                    $journal->record($context, 'linen_guard', 'skipped');
                }
                $results[] = ['target' => $target['key'], 'status' => $previous === 'sent' ? 'skipped_duplicate' : 'blocked_uncertain'];

                continue;
            }
            if ($context !== null) {
                $journal->record($context, 'linen_guard', 'complete');
            }
            try {
                $messageId = app(TelegramOutboundMonitor::class)->withContext($context ?? [
                    'notification_type' => 'linen_defect',
                    'district' => $target['district'],
                ], fn () => $mode === 'centralized'
                    ? $bot->sendMessage($target['chat'], $text, $target['thread'])
                    : $bot->sendAnalyticsMessage($target['chat'], $text, $target['thread']));
            } catch (Throwable) {
                $messageId = null;
            }
            if ($messageId !== null) {
                try {
                    Cache::put($guard, 'sent', now()->addDays(30));
                } catch (Throwable) {
                    // The reserved attempt prevents automatic retries after an uncertain cache update.
                    if ($context !== null) {
                        $journal->record($context, 'linen_guard', 'uncertain');
                    }
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

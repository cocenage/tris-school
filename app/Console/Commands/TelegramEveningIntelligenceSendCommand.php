<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDigestBuildJournal;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramLinenDefectReportBuilder;
use App\Services\Telegram\TelegramOutboundMonitor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramEveningIntelligenceSendCommand extends Command
{
    private const DELIVERY_GUARD_DAYS = 30;

    private const REPORT_DISTRICTS = ['navigli', 'lodi', 'lambrate', 'como', 'certosa'];

    protected $signature = 'telegram:evening-intelligence-send
        {--date= : Calendar date in the application timezone; defaults to today}
        {--district= : Optional configured district key}
        {--dry-run : Render district summaries without calling Telegram}
        {--json : Emit machine-readable per-district results}';

    protected $description = 'Preview or safely deliver district evening intelligence to the configured duty topic';

    public function handle(
        TelegramDistrictRouteRegistry $districts,
        TelegramEveningIntelligenceBuilder $builder,
        TelegramLinenDefectReportBuilder $linen,
        TelegramDigestFormatter $formatter,
        TelegramBotService $bot,
    ): int {
        $date = $this->dateOption();

        if ($date === null) {
            return self::FAILURE;
        }

        $deliveryMode = (string) config('services.telegram.evening_intelligence_delivery_mode', 'centralized');

        if (! in_array($deliveryMode, ['centralized', 'per-district'], true)) {
            $this->error('Invalid evening intelligence delivery mode.');

            return self::FAILURE;
        }

        $sourceRoutes = ($deliveryMode === 'centralized' ? $districts->sourceRoutes() : $districts->routes())
            ->whereIn('key', self::REPORT_DISTRICTS)->values();
        $routes = filled($this->option('district'))
            ? $sourceRoutes->filter(fn (array $route): bool => $route['key'] === mb_strtolower(trim((string) $this->option('district'))))
            : $sourceRoutes;

        if ($routes->isEmpty()) {
            $this->error('No complete district source routes are configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! (bool) config('services.telegram.evening_intelligence_delivery_enabled', false)) {
            $this->error('Evening intelligence delivery is disabled. Use --dry-run for preview.');

            return self::FAILURE;
        }

        $centralChatId = trim((string) config('services.telegram.evening_intelligence_central_chat_id'));
        $centralThreadId = trim((string) config('services.telegram.evening_intelligence_central_thread_id'));

        if (! $dryRun && $deliveryMode === 'centralized' && ($centralChatId === '' || $centralThreadId === '')) {
            $this->error('Central evening intelligence chat and duty thread must be configured.');

            return self::FAILURE;
        }

        $journal = app(TelegramDigestBuildJournal::class);
        $journalContexts = [];
        if (! $dryRun) {
            foreach ($routes as $route) {
                $targetChatId = $deliveryMode === 'centralized' ? $centralChatId : (string) $route['chat_id'];
                $targetThreadId = $deliveryMode === 'centralized' ? $centralThreadId : (string) $route['duty_thread_id'];
                $context = [
                    'operation_key' => 'evening_intelligence:'.$date->toDateString().':'.$route['key'],
                    'notification_type' => 'evening_intelligence',
                    'bot' => $deliveryMode === 'centralized' ? 'work' : 'analytics',
                    'chat_id' => $targetChatId,
                    'message_thread_id' => $targetThreadId,
                    'district' => $route['key'],
                    'source_type' => 'TelegramOperationalEvent',
                    'source_id' => $date->toDateString(),
                    'scheduled_for' => $date->copy()->setTime(20, 30),
                ];
                $journalContexts[$route['key']] = $context;
                $journal->reserve($context);
            }
        }

        if (! $dryRun && $date->isSameDay(now(config('app.timezone', 'Europe/Rome')))
            && Artisan::call('telegram:operational-replay', ['--through-now' => true, '--json' => true]) !== self::SUCCESS) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'evening_source', 'failure_unclassified');
            }
            $this->error('Current-day operational catch-up failed; evening delivery was not attempted.');

            return self::FAILURE;
        }

        $results = [];
        if (! filled($this->option('district'))) {
            $configuredRoutes = $districts->diagnostics()->whereIn('key', self::REPORT_DISTRICTS)->keyBy('key');
            foreach (self::REPORT_DISTRICTS as $key) {
                if (! $configuredRoutes->has($key)) {
                    $results[] = $this->result(['key' => $key, 'label' => ucfirst($key)], $date,
                        'failed', 0, 0, 'source_route_incomplete');
                }
            }
            foreach ($configuredRoutes as $configured) {
                if (! $routes->contains('key', $configured['key'])) {
                    $results[] = $this->result($configured, $date, 'failed', 0, 0, 'source_route_incomplete');
                }
            }
        }
        $linenSourceFailed = false;
        try {
            $linenReport = $linen->build($date);
        } catch (Throwable) {
            $linenReport = ['cases' => [], 'incomplete' => true];
            $linenSourceFailed = true;
        }

        $linenDistricts = collect($linenReport['districts'] ?? []);
        if ($linenSourceFailed) {
            foreach ($journalContexts as $context) {
                $journal->record($context, 'evening_linen_source', 'failure_unclassified');
            }
        } else {
            foreach ($journalContexts as $context) {
                $districtKey = (string) $context['district'];
                $linenDistrict = $linenDistricts->get($districtKey);
                $linenSourceMessages = (int) ($linenDistrict['source_messages'] ?? 0);
                $linenObservedMessages = (int) ($linenDistrict['observed_messages'] ?? 0);
                $linenCases = collect($linenReport['cases'] ?? [])->where('district', $districtKey)->count();
                $linenSourceOutcome = $linenDistrict === null
                    ? 'failure_unclassified'
                    : (($linenDistrict['source_status'] ?? null) === 'unavailable' && $linenSourceMessages === 0
                        ? 'no_input'
                        : (($linenDistrict['source_status'] ?? null) !== 'available'
                            || $linenObservedMessages < $linenSourceMessages
                            ? 'source_error' : 'loaded'));

                $journal->record($context, 'evening_linen_source', $linenSourceOutcome, [
                    'input_count' => $linenSourceMessages,
                    'source_messages' => $linenSourceMessages,
                    'observed_messages' => $linenObservedMessages,
                ]);
                $journal->record($context, 'linen_filter', $linenCases > 0 ? 'complete' : 'no_significant_events', [
                    'input_count' => $linenSourceMessages,
                    'events_included' => $linenCases,
                ]);
            }
        }

        foreach ($routes as $route) {
            $context = $journalContexts[$route['key']] ?? null;
            try {
                $preview = $builder->build($date, ['district' => $route]);
            } catch (Throwable) {
                if ($context !== null) {
                    $journal->record($context, 'evening_source', 'failure_unclassified');
                }
                $results[] = $this->result($route, $date, 'failed', 0, 0, 'ledger_unavailable');

                continue;
            }

            $preview['linen_report'] = $linenReport;

            if ($context !== null) {
                $quality = $preview['data_quality'] ?? [];
                $sourceMessages = (int) ($quality['source_messages'] ?? 0);
                $observedMessages = (int) ($quality['observed_messages'] ?? 0);
                $eventsConsidered = (int) ($preview['events_considered'] ?? 0);
                $sourceOutcome = ($quality['ledger'] ?? 'unavailable') !== 'available'
                    ? 'failure_unclassified'
                    : (($quality['source_status'] ?? null) === 'incomplete'
                        ? 'source_error'
                        : ($sourceMessages === 0 && $eventsConsidered === 0 ? 'no_input' : 'loaded'));
                $journal->record($context, 'evening_source', $sourceOutcome, [
                    'source_messages' => $sourceMessages,
                    'observed_messages' => $observedMessages,
                    'events_considered' => $eventsConsidered,
                ]);
                $journal->record($context, 'evening_filter', (int) ($preview['events_included'] ?? 0) > 0 ? 'complete' : 'no_significant_events', [
                    'events_considered' => $eventsConsidered,
                    'events_included' => (int) ($preview['events_included'] ?? 0),
                    'events_omitted' => (int) ($preview['events_omitted'] ?? 0),
                ]);
            }

            try {
                $text = $formatter->eveningIntelligence($preview);
            } catch (Throwable $exception) {
                if ($context !== null) {
                    $journal->record($context, 'evening_format', 'calculation_error');
                }

                throw $exception;
            }
            $materialEvents = (int) $preview['events_included'];

            if ($context !== null) {
                $messagePresent = trim($text) !== '';
                $journal->record($context, 'evening_format', $messagePresent ? 'built' : 'no_message', [
                    'message_present' => $messagePresent,
                ]);
            }

            if ($dryRun) {
                $results[] = $this->result($route, $date, 'previewed', $materialEvents, 0);

                if (! $this->option('json')) {
                    $this->newLine();
                    foreach (explode("\n", $text) as $line) {
                        $this->line($line);
                    }
                    $this->line('Предпросмотр: отправка в Telegram отключена.');
                }

                continue;
            }

            $targetChatId = $deliveryMode === 'centralized' ? $centralChatId : (string) $route['chat_id'];
            $targetThreadId = $deliveryMode === 'centralized' ? $centralThreadId : (string) $route['duty_thread_id'];
            $deliveryKey = 'telegram_evening_intelligence_v1:'.hash('sha256', implode('|', [
                $date->toDateString(), $route['key'], $deliveryMode, $targetChatId, $targetThreadId,
            ]));

            try {
                $reserved = Cache::add($deliveryKey, 'attempted', now()->addDays(self::DELIVERY_GUARD_DAYS));
                $previous = $reserved ? null : Cache::get($deliveryKey);
            } catch (Throwable) {
                if ($context !== null) {
                    $journal->record($context, 'evening_guard', 'failure_unclassified');
                }
                $results[] = $this->result($route, $date, 'failed', $materialEvents, 0, 'idempotency_store_unavailable');

                continue;
            }

            if (! $reserved) {
                if ($context !== null) {
                    $journal->record($context, 'evening_guard', 'skipped');
                }
                $status = $previous === 'sent' ? 'skipped_duplicate' : 'blocked_uncertain';
                $results[] = $this->result($route, $date, $status, $materialEvents, 0);

                continue;
            }

            if ($context !== null) {
                $journal->record($context, 'evening_guard', 'complete');
            }

            try {
                $messageId = app(TelegramOutboundMonitor::class)->withContext($context ?? [
                    'notification_type' => 'evening_intelligence', 'district' => $route['key'],
                    'scheduled_for' => $date->copy()->setTime(20, 30),
                ], fn () => $deliveryMode === 'centralized'
                    ? $bot->sendMessage($targetChatId, $text, $targetThreadId)
                    : $bot->sendAnalyticsMessage($targetChatId, $text, $targetThreadId));
            } catch (Throwable) {
                $messageId = null;
            }

            if ($messageId !== null) {
                try {
                    Cache::put($deliveryKey, 'sent', now()->addDays(self::DELIVERY_GUARD_DAYS));
                } catch (Throwable) {
                    // The reserved attempt still prevents an automatic retry after an uncertain cache update.
                    if ($context !== null) {
                        $journal->record($context, 'evening_guard', 'uncertain');
                    }
                }
            }

            $results[] = $this->result(
                $route,
                $date,
                $messageId === null ? 'failed' : 'sent',
                $materialEvents,
                $messageId === null ? 0 : 1,
                $messageId === null ? 'telegram_send_failed' : null,
            );
        }

        $payload = [
            'date' => $date->toDateString(),
            'dry_run' => $dryRun,
            'results' => $results,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $result) {
                $this->line(sprintf(
                    '%s: %s (%d событий, Telegram actions: %d)',
                    $result['label'],
                    $result['status'],
                    $result['material_events'],
                    $result['telegram_actions'],
                ));
            }
        }

        return collect($results)->contains(fn (array $result): bool => in_array($result['status'], ['failed', 'blocked_uncertain'], true))
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function dateOption(): ?Carbon
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $value = filled($this->option('date'))
            ? (string) $this->option('date')
            : now($timezone)->toDateString();

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
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

    /** @return array<string, mixed> */
    private function result(
        array $route,
        Carbon $date,
        string $status,
        int $materialEvents,
        int $telegramActions,
        ?string $reason = null,
    ): array {
        return array_filter([
            'district' => $route['key'],
            'label' => $route['label'],
            'date' => $date->toDateString(),
            'status' => $status,
            'material_events' => $materialEvents,
            'telegram_actions' => $telegramActions,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);
    }
}

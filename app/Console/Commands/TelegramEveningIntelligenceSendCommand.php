<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class TelegramEveningIntelligenceSendCommand extends Command
{
    protected $signature = 'telegram:evening-intelligence-send
        {--date= : Calendar date in the application timezone; defaults to today}
        {--district= : Optional configured district key}
        {--dry-run : Render district summaries without calling Telegram}
        {--json : Emit machine-readable per-district results}';

    protected $description = 'Preview or safely deliver district evening intelligence to the configured duty topic';

    public function handle(
        TelegramDistrictRouteRegistry $districts,
        TelegramEveningIntelligenceBuilder $builder,
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

        $sourceRoutes = $deliveryMode === 'centralized' ? $districts->sourceRoutes() : $districts->routes();
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

        if (! $dryRun && $date->isSameDay(now(config('app.timezone', 'Europe/Rome')))
            && Artisan::call('telegram:operational-replay', ['--through-now' => true, '--json' => true]) !== self::SUCCESS) {
            $this->error('Current-day operational catch-up failed; evening delivery was not attempted.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($routes as $route) {
            try {
                $preview = $builder->build($date, ['district' => $route]);
            } catch (Throwable) {
                $results[] = $this->result($route, $date, 'failed', 0, 0, 'ledger_unavailable');

                continue;
            }

            if ($preview['no_material_events']) {
                $results[] = $this->result($route, $date, 'skipped_empty', 0, 0);

                continue;
            }

            $text = $formatter->eveningIntelligence($preview);
            $materialEvents = (int) $preview['events_included'];

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

            try {
                $messageId = $bot->sendAnalyticsMessage(
                    $deliveryMode === 'centralized' ? $centralChatId : (string) $route['chat_id'],
                    $text,
                    $deliveryMode === 'centralized' ? $centralThreadId : (string) $route['duty_thread_id'],
                );
            } catch (Throwable) {
                $messageId = null;
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

        return collect($results)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
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

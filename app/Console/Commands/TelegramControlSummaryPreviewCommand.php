<?php

namespace App\Console\Commands;

use App\Models\TelegramOutboundOperation;
use App\Models\TelegramScheduledControlSummaryDelivery;
use App\Services\Telegram\TelegramScheduledControlSummaryBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDemoFixture;
use App\Services\Telegram\TelegramScheduledControlSummaryRichFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class TelegramControlSummaryPreviewCommand extends Command
{
    protected $signature = 'telegram:control-summary-preview {--date=} {--json} {--fallback} {--demo} {--delivery-status : Read the summary delivery journal without building or sending a message}';

    protected $description = 'Read-only preview of scheduled control replies';

    public function handle(
        TelegramScheduledControlSummaryBuilder $builder,
        TelegramScheduledControlSummaryRichFormatter $formatter,
        TelegramScheduledControlSummaryDemoFixture $demo,
    ): int {
        try {
            $date = $this->option('date') ?: CarbonImmutable::now('Europe/Rome')->toDateString();
            $builder->day($date);
            if ($this->option('delivery-status')) {
                $this->line(json_encode($this->deliveryStatus($date), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $summary = $this->option('demo') ? $demo->build($date) : $builder->build($date);
            $this->line($this->option('json')
                ? json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : ($this->option('fallback') ? $formatter->fallbackHtml($summary) : $formatter->richMessage($summary)['markdown']));

            return self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function deliveryStatus(string $date): array
    {
        $chat = (string) config('services.telegram.scheduled_summary_chat_id');
        $topic = (string) config('services.telegram.scheduled_summary_thread_id');
        $deliveries = Schema::hasTable('telegram_scheduled_control_summary_deliveries')
            ? TelegramScheduledControlSummaryDelivery::query()->where('summary_date', $date)->orderByDesc('id')
                ->get(['status', 'queued_at', 'sent_at', 'telegram_message_id', 'chat_id', 'message_thread_id'])
                ->map(fn (TelegramScheduledControlSummaryDelivery $delivery): array => [
                    'status' => $delivery->status,
                    'queued_at' => $delivery->queued_at?->toIso8601String(),
                    'sent_at' => $delivery->sent_at?->toIso8601String(),
                    'telegram_message_id' => $delivery->telegram_message_id,
                    'matches_current_destination' => $chat === $delivery->chat_id && $topic === (string) $delivery->message_thread_id,
                ])->all()
            : null;
        $hasStages = Schema::hasTable('telegram_outbound_operation_events');
        $operations = Schema::hasTable('telegram_outbound_operations')
            ? TelegramOutboundOperation::query()->where('notification_type', 'scheduled_control_summary')
                ->whereDate('scheduled_for', $date)->orderByDesc('id')->get()
                ->map(fn (TelegramOutboundOperation $operation): array => [
                    'status' => $operation->status,
                    'attempts' => $operation->attempts,
                    'error_code' => $operation->error_code,
                    'error_reason' => $operation->error_reason,
                    'stages' => $hasStages ? $operation->events()->reorder('id', 'desc')->limit(12)
                        ->get(['stage', 'outcome'])->reverse()->values()->all() : null,
                ])->all()
            : null;

        return [
            'date' => $date,
            'enabled' => (bool) config('services.telegram.scheduled_summary_enabled'),
            'analytics_bot_configured' => filled(config('services.telegram.analytics_bot_token')),
            'chat_configured' => $chat !== '',
            'topic_configured' => $topic !== '',
            'deliveries' => $deliveries,
            'operations' => $operations,
        ];
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\TelegramOutboundOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TelegramDigestBuildJournal
{
    private const MAX_EVENTS_PER_OPERATION = 60;

    private const STAGES = [
        'build', 'calendar_source', 'calendar_filter', 'calendar_format',
        'morning_alert_source', 'morning_strike_source', 'morning_weather_source', 'morning_filter', 'morning_format',
        'controls_source', 'controls_filter', 'controls_format', 'controls_queue',
        'evening_source', 'evening_filter', 'evening_linen_source', 'evening_format', 'evening_guard',
        'linen_source', 'linen_filter', 'linen_format', 'linen_guard',
        'send_attempt', 'telegram_response',
    ];

    private const OUTCOMES = [
        'started', 'loaded', 'complete', 'no_input', 'no_significant_events',
        'source_error', 'calculation_error', 'failure_unclassified', 'no_message', 'built',
        'inline', 'queued', 'queue_failed', 'skipped', 'attempted', 'accepted', 'rejected', 'uncertain',
    ];

    private const METRICS = [
        'input_count', 'filtered_count', 'included_count', 'message_present',
        'source_messages', 'observed_messages', 'events_considered', 'events_included', 'events_omitted',
        'controls_count', 'responses_count', 'queued_count', 'attempt', 'http_status', 'error_code',
    ];

    public function __construct(private TelegramOutboundMonitor $monitor) {}

    /** Reserve the existing outbound operation before any digest source is read. */
    public function reserve(array $context): void
    {
        try {
            $this->monitor->expect($context);
        } catch (Throwable) {
            // A failed journal reservation must not prevent the digest from continuing.
        }

        $this->record($context, 'build', 'started');
    }

    /** Append bounded, allow-listed diagnostics without affecting the delivery path. */
    public function record(array $context, string $stage, string $outcome, array $metrics = []): void
    {
        if (! in_array($stage, self::STAGES, true) || ! in_array($outcome, self::OUTCOMES, true)) {
            return;
        }

        $operationKey = $context['operation_key'] ?? null;
        if (! is_string($operationKey) || trim($operationKey) === '') {
            return;
        }

        try {
            if (! Schema::hasTable('telegram_outbound_operations')
                || ! Schema::hasTable('telegram_outbound_operation_events')) {
                return;
            }

            $safeMetrics = [];
            foreach ($metrics as $key => $value) {
                if (! in_array($key, self::METRICS, true)) {
                    continue;
                }

                if (is_bool($value) || (is_int($value) && $value >= 0)) {
                    $safeMetrics[$key] = $value;
                }
            }

            DB::transaction(function () use ($operationKey, $stage, $outcome, $safeMetrics): void {
                $operation = TelegramOutboundOperation::query()
                    ->where('operation_key', $operationKey)
                    ->lockForUpdate()
                    ->first();
                if ($operation === null || $operation->events()->count() >= self::MAX_EVENTS_PER_OPERATION) {
                    return;
                }

                $operation->events()->create([
                    'stage' => $stage,
                    'outcome' => $outcome,
                    'metrics' => $safeMetrics === [] ? null : $safeMetrics,
                ]);
            });
        } catch (Throwable $exception) {
            // Diagnostic writes must never block a digest or expose exception text.
            try {
                Log::warning('Telegram digest build journal unavailable.', ['error' => class_basename($exception)]);
            } catch (Throwable) {
                // A logging backend failure is also diagnostic-only.
            }
        }
    }
}

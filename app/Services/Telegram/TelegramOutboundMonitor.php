<?php

namespace App\Services\Telegram;

use App\Models\TelegramOutboundOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use WeakMap;

/** Observes every outbound Telegram content request, including legacy direct HTTP calls. */
class TelegramOutboundMonitor
{
    private WeakMap $inFlight;

    /** Laravel creates a new Request object for connection failures. */
    private array $inFlightByRoute = [];

    private ?array $context = null;

    public function __construct()
    {
        $this->inFlight = new WeakMap;
    }

    /** Reserve a business notification before it reaches the transport. */
    public function expect(array $context): void
    {
        $this->safely(function () use ($context): void {
            if (empty($context['operation_key']) || ! Schema::hasTable('telegram_outbound_operations')) {
                return;
            }

            TelegramOutboundOperation::query()->firstOrCreate(
                ['operation_key' => $context['operation_key']],
                [
                    'notification_type' => $context['notification_type'] ?? 'other',
                    'method' => $context['method'] ?? 'sendMessage',
                    'bot' => $context['bot'] ?? 'work',
                    'chat_id' => $context['chat_id'] ?? null,
                    'message_thread_id' => $context['message_thread_id'] ?? null,
                    'source_type' => $context['source_type'] ?? null,
                    'source_id' => isset($context['source_id']) ? (string) $context['source_id'] : null,
                    'scheduled_for' => $context['scheduled_for'] ?? null,
                    'status' => 'expected',
                ],
            );
        });
    }

    /** @template T */
    public function withContext(array $context, callable $send): mixed
    {
        $previous = $this->context;
        $this->context = $context;

        try {
            return $send();
        } finally {
            $this->context = $previous;
        }
    }

    public function sending(RequestSending $event): void
    {
        $this->safely(function () use ($event): void {
            $method = $this->telegramMethod($event->request);
            if ($method === null || ! Schema::hasTable('telegram_outbound_operations')) {
                return;
            }

            $data = $event->request->data();
            $context = $this->context ?? [];
            $source = $this->sourceReference();
            $key = $context['operation_key'] ?? null;
            $attributes = [
                'notification_type' => $context['notification_type'] ?? $this->notificationType(),
                'method' => $method,
                'bot' => $this->bot($event->request),
                'chat_id' => isset($data['chat_id']) ? (string) $data['chat_id'] : null,
                'message_thread_id' => isset($data['message_thread_id']) ? (string) $data['message_thread_id'] : null,
                'district' => $context['district'] ?? $this->districtForChat(isset($data['chat_id']) ? (string) $data['chat_id'] : null),
                'source_type' => $context['source_type'] ?? $source['type'] ?? null,
                'source_id' => isset($context['source_id']) ? (string) $context['source_id'] : ($source['id'] ?? null),
                'scheduled_for' => $context['scheduled_for'] ?? null,
            ];

            $operation = $key !== null
                ? TelegramOutboundOperation::query()->firstOrCreate(['operation_key' => $key], $attributes)
                : TelegramOutboundOperation::query()->create($attributes);

            $operation->fill($attributes);
            $operation->attempts++;
            $operation->attempted_at = now();
            $operation->status = 'pending';
            $operation->error_code = null;
            $operation->error_reason = null;
            $operation->save();

            $this->inFlight[$event->request] = $operation->getKey();
            $this->inFlightByRoute[$this->routeFingerprint($event->request)] = $operation->getKey();
        });
    }

    public function received(ResponseReceived $event): void
    {
        $this->safely(function () use ($event): void {
            $operation = $this->operation($event->request);
            if ($operation === null) {
                return;
            }

            $result = $event->response->json();
            $accepted = $event->response->successful() && data_get($result, 'ok') === true;
            $messageId = data_get($result, 'result.message_id') ?: data_get($result, 'result.0.message_id');
            $isEdit = str_starts_with($operation->method, 'editMessage');
            $hasReceipt = $isEdit || is_numeric($messageId);

            $operation->update([
                'status' => $accepted ? ($hasReceipt ? ($isEdit ? 'edited' : 'sent') : 'uncertain') : 'failed',
                'finished_at' => now(),
                'telegram_message_id' => is_numeric($messageId) ? (int) $messageId : $operation->telegram_message_id,
                'error_code' => $accepted ? null : (int) (data_get($result, 'error_code') ?: $event->response->status()),
                // Telegram descriptions can contain message content and are deliberately excluded.
                'error_reason' => $accepted ? ($hasReceipt ? null : 'receipt_missing') : 'telegram_api_rejected',
            ]);
            $this->forget($event->request);
        });
    }

    public function failed(ConnectionFailed $event): void
    {
        $this->safely(function () use ($event): void {
            $this->operation($event->request)?->update([
                'status' => 'uncertain',
                'finished_at' => now(),
                'error_reason' => 'connection_failed',
            ]);
            $this->forget($event->request);
        });
    }

    private function operation(Request $request): ?TelegramOutboundOperation
    {
        $id = $this->inFlight[$request] ?? $this->inFlightByRoute[$this->routeFingerprint($request)] ?? null;

        return $id === null ? null : TelegramOutboundOperation::query()->find($id);
    }

    private function routeFingerprint(Request $request): string
    {
        $data = $request->data();

        return hash('sha256', implode('|', [
            $request->url(),
            (string) ($data['chat_id'] ?? ''),
            (string) ($data['message_thread_id'] ?? ''),
            (string) ($data['message_id'] ?? ''),
        ]));
    }

    private function forget(Request $request): void
    {
        unset($this->inFlight[$request], $this->inFlightByRoute[$this->routeFingerprint($request)]);
    }

    private function telegramMethod(Request $request): ?string
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (! is_string($path) || parse_url($request->url(), PHP_URL_HOST) !== 'api.telegram.org') {
            return null;
        }

        if (! preg_match('~^/bot[^/]+/([A-Za-z]+)$~', $path, $matches)) {
            return null;
        }

        $method = $matches[1];

        return in_array($method, [
            'sendMessage', 'sendRichMessage', 'sendPhoto', 'sendVideo', 'sendDocument',
            'sendAnimation', 'sendMediaGroup', 'editMessageText', 'editMessageCaption',
            'editMessageMedia', 'editMessageReplyMarkup',
        ], true) ? $method : null;
    }

    private function bot(Request $request): string
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        foreach (['work' => 'bot_token', 'scheduled' => 'scheduled_bot_token', 'analytics' => 'analytics_bot_token'] as $name => $config) {
            $token = config('services.telegram.'.$config);
            if (is_string($token) && $token !== '' && str_starts_with($path, '/bot'.$token.'/')) {
                return $name;
            }
        }

        return 'unknown';
    }

    private function notificationType(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 35) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_ends_with($class, 'TelegramWorkWebhookController')) {
                $method = $frame['function'] ?? '';
                if (str_contains($method, 'DayOff')) {
                    return 'day_off';
                }
                if (str_contains($method, 'Access')) {
                    return 'user_access';
                }
                if (str_contains($method, 'WorkControl')) {
                    return 'scheduled_control';
                }
                if (str_contains($method, 'PrivateFallback')) {
                    return 'assistant';
                }
            }
            foreach ([
                'DayOffRequestTelegramService' => 'day_off',
                'VacationRequestTelegramService' => 'vacation',
                'InventoryRequestTelegramService' => 'inventory',
                'StaffFormTelegramService' => 'staff_form',
                'TelegramUserNotificationService' => 'user_access',
                'TaskTelegramNotificationService' => 'task',
                'TelegramAssistantService' => 'assistant',
                'TelegramInstructionAutoReplyService' => 'instruction',
                'DeliverScheduledTelegramMessage' => 'scheduled_control',
                'DeliverScheduledControlSummary' => 'scheduled_control_summary',
                'TelegramScheduledInboundProcessor' => 'scheduled_control',
                'DeliverMobilityAlert' => 'mobility_alert',
                'MobilityAdminAlertsCommand' => 'mobility_admin_alert',
                'TelegramEveningIntelligenceSendCommand' => 'evening_intelligence',
                'TelegramLinenDefectReportCommand' => 'linen_defect',
                'MobilityDigestCommand' => 'morning_digest',
                'SendTomorrowCalendarEventsNotification' => 'calendar',
                'TelegramApartmentHandoffSendCommand' => 'apartment_handoff',
            ] as $caller => $type) {
                if (str_ends_with($class, $caller)) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    private function sourceReference(): ?array
    {
        $fallback = null;
        foreach (debug_backtrace(0, 35) as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (! $argument instanceof Model || $argument->getKey() === null) {
                    continue;
                }

                $reference = ['type' => class_basename($argument), 'id' => (string) $argument->getKey()];
                if ($reference['type'] !== 'User') {
                    return $reference;
                }
                $fallback ??= $reference;
            }
        }

        return $fallback;
    }

    private function districtForChat(?string $chatId): ?string
    {
        if ($chatId === null || $chatId === '') {
            return null;
        }

        $match = null;
        foreach (config('services.telegram.digest_districts', []) as $district => $route) {
            if (is_array($route) && (string) ($route['chat_id'] ?? '') === $chatId) {
                if ($match !== null) {
                    return null;
                }
                $match = (string) $district;
            }
        }

        return $match;
    }

    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            // Monitoring must never change the existing Telegram delivery path.
            Log::warning('Telegram outbound monitoring unavailable.', ['error' => class_basename($exception)]);
        }
    }
}

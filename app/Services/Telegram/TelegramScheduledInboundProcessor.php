<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;

class TelegramScheduledInboundProcessor
{
    public function __construct(
        private readonly TelegramUpdateIngestService $ingest,
        private readonly TelegramScheduledControlResponseService $responses,
        private readonly TelegramScheduledMessageDeliveryService $deliveries,
        private readonly TelegramBotService $telegram,
    ) {}

    /** @return array{ok: true, skipped?: string, message_id?: int|null} */
    public function process(array $update): array
    {
        if (isset($update['callback_query'])) {
            return $this->processCallback($update['callback_query']);
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (! $message || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return ['ok' => true, 'skipped' => 'no_group_message'];
        }

        $chatId = (string) data_get($message, 'chat.id');
        $allowed = config('services.telegram.scheduled_webhook_allowed_chat_ids', []);
        $permitted = $allowed !== []
            ? in_array($chatId, $allowed, true)
            : TelegramScheduledMessageDelivery::query()->where('chat_id', $chatId)->exists();

        if (! $permitted) {
            return ['ok' => true, 'skipped' => 'chat_not_allowed'];
        }

        $stored = $this->ingest->ingest($update);

        if ($stored) {
            $response = $this->responses->capture($stored, $message);
            $delivery = $response?->delivery;

            if ($delivery !== null && ScheduledControlTypes::triggersClosingPayments((string) $delivery->control_type) && $response?->classification === 'confirmed') {
                if (! $this->deliveries->queueClosingPayments($delivery)) {
                    throw new \RuntimeException('Could not queue the scheduled closing payment question.');
                }

            } elseif ($delivery?->control_type === ScheduledControlTypes::CLOSING_PAYMENTS
                && in_array($response?->classification, ['confirmed', 'problem'], true)) {
                $this->telegram->clearScheduledMessageButtons((string) $delivery->chat_id, (int) $delivery->telegram_message_id);
            }
        }

        return ['ok' => true, 'message_id' => $stored?->id];
    }

    private function processCallback(array $callbackQuery): array
    {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $allowed = config('services.telegram.scheduled_webhook_allowed_chat_ids', []);
        if ($allowed !== [] && ! in_array((string) $chatId, $allowed, true)) {
            $callbackId = $callbackQuery['id'] ?? null;
            if (is_string($callbackId) && $callbackId !== '') {
                $this->telegram->answerScheduledCallback($callbackId, 'Нет доступа к этому чату.');
            }

            return ['ok' => true, 'skipped' => 'chat_not_allowed'];
        }

        $result = $this->responses->captureCallback($callbackQuery);
        $delivery = $result['delivery'];
        $response = $result['response'];
        $callbackId = $callbackQuery['id'] ?? null;
        $answer = 'Эта кнопка больше не действует.';

        if ($delivery !== null && $response !== null) {
            if (ScheduledControlTypes::triggersClosingPayments((string) $delivery->control_type)
                && $response->classification === 'confirmed') {
                if (! $this->deliveries->queueClosingPayments($delivery)) {
                    if (is_string($callbackId) && $callbackId !== '') {
                        $this->telegram->answerScheduledCallback($callbackId, 'Ответ сохранён, но вопрос о доплатах пока не отправлен. Повторяем обработку.');
                    }

                    throw new \RuntimeException('Could not queue the scheduled closing payment question.');
                }

                $answer = 'Ответ сохранён. Доплаты проверяются после подтверждения всех районов.';
            } elseif (ScheduledControlTypes::triggersClosingPayments((string) $delivery->control_type) && $result['action'] === 'no') {
                $answer = 'Ответ сохранён. Его можно изменить на «Да». Зафиксирован дежурный ответ.';
            } elseif ($delivery->control_type === ScheduledControlTypes::CLOSING_PAYMENTS) {
                $answer = 'Итоговый ответ сохранён.';
                $this->telegram->clearScheduledMessageButtons((string) $delivery->chat_id, (int) $delivery->telegram_message_id);
            }
        }

        if (is_string($callbackId) && $callbackId !== '') {
            $this->telegram->answerScheduledCallback($callbackId, $answer);
        }

        return ['ok' => true, 'callback_status' => $result['status']];
    }
}

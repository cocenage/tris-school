<?php

namespace App\Services\Forms;

use App\Models\StructuredRequestDecision;
use App\Models\TelegramOutboundOperation;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramRequestNotSent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramStructuredRequestService
{
    public function __construct(
        private readonly StructuredRequestReview $review,
        private readonly TelegramBotService $telegram,
        private readonly TelegramOutboundMonitor $monitor,
    ) {}

    private function name(?User $user): string
    {
        if (! $user) {
            return 'Неизвестный сотрудник';
        }
        return trim((string) $user->name)
            ?: (trim((string) $user->telegram_first_name.' '.(string) $user->telegram_last_name)
                ?: (filled($user->telegram_username) ? '@'.ltrim($user->telegram_username, '@') : 'Сотрудник #'.$user->id));
    }

    private function status(string $status): string
    {
        return match ($status) {
            'approved', 'issued' => '✅ Одобрено',
            'rejected', 'cancelled' => '❌ Отказано',
            'partially_approved', 'partially_issued' => '🟠 Частично одобрено',
            default => '🟡 Ожидает решения',
        };
    }

    private function itemLines(string $kind, array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $mark = match ($item['status']) {
                'approved', 'issued' => '✅', 'rejected', 'cancelled' => '❌',
                'partially_approved', 'partially_issued' => '🟠', default => '⏳',
            };
            $line = $mark.' '.e($item['label']);
            if ($kind === 'inventory') {
                $line .= $item['status'] === 'pending' ? ' — '.$item['requested'] : ' — '.$item['approved'].' из '.$item['requested'];
            }
            $lines[] = $line;
            if ($item['reason'] !== '') {
                $lines[] = '<i>'.e($item['reason']).'</i>';
            }
        }
        return $lines;
    }

    public function assertLength(string $html, int $limit = 4096): void
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2 > $limit) {
            throw ValidationException::withMessages(['items' => 'Сообщение слишком длинное для Telegram. Сократите комментарии.']);
        }
    }

    public function card(string $kind, Model $record, array $items, ?User $reviewer = null): string
    {
        $record->loadMissing('user');
        $lines = [
            '<b>'.$this->review->label($kind).' · #'.$record->id.'</b>',
            '',
            '👤 <b>Сотрудник:</b> '.e(Str::limit($this->name($record->user), 120)),
            '',
            '<b>'.($kind === 'inventory' ? 'Позиции' : 'Даты').':</b>',
            ...$this->itemLines($kind, $items),
        ];
        $original = trim((string) ($kind === 'inventory' ? $record->comment : $record->reason));
        if ($original !== '') {
            $lines[] = '';
            $lines[] = '💬 <b>Комментарий сотрудника:</b>';
            $lines[] = e(Str::limit($original, 700));
        }
        $lines[] = '';
        $lines[] = '<b>'.$this->status((string) $record->status).'</b>';
        if ($reviewer) {
            $lines[] = 'Рассмотрел: '.e(Str::limit($this->name($reviewer), 120));
            $lines[] = now('Europe/Rome')->format('d.m.Y · H:i');
            if (filled($record->admin_comment)) {
                $lines[] = '';
                $lines[] = $kind === 'inventory'
                    ? StructuredRequestReview::pickupHtml($record->admin_comment)
                    : e($record->admin_comment);
            }
        }

        return implode("\n", $lines);
    }

    public function result(string $kind, Model $record, array $items): string
    {
        $lines = [
            '<b>'.$this->review->label($kind).' · #'.$record->id.'</b>',
            '',
            '<b>'.$this->status((string) $record->status).'</b>',
            '',
            ...$this->itemLines($kind, $items),
        ];
        if (filled($record->admin_comment)) {
            $lines[] = '';
            $lines[] = $kind === 'inventory'
                ? StructuredRequestReview::pickupHtml($record->admin_comment)
                : e($record->admin_comment);
        }
        return implode("\n", $lines);
    }

    public function keyboard(string $kind, int $id, bool $pending): array
    {
        $buttons = [];
        $bot = trim((string) config('services.telegram.bot_username'), ' @');
        if ($pending && preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', $bot)) {
            $buttons[] = [['text' => 'Рассмотреть заявку', 'url' => 'https://t.me/'.$bot.'?startapp=review_'.$kind.'_'.$id.'&mode=compact']];
        }
        $buttons[] = [['text' => 'Открыть заявку', 'url' => $this->review->resourceUrl($kind, $id)]];
        return ['inline_keyboard' => $buttons];
    }

    public function sendCreated(string $kind, Model $record): void
    {
        $record->refresh();
        if ($record->telegram_review_version !== null || filled($record->telegram_source_message_id)) {
            return;
        }
        [$chat, $thread] = $this->review->destination($kind);
        $bot = trim((string) config('services.telegram.bot_username'), ' @');
        if ($chat === '' || ($thread !== '' && ! ctype_digit($thread)) || ! filled(config('services.telegram.bot_token'))
            || ! preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', $bot)) {
            throw new \RuntimeException('Structured review Telegram destination is not configured.');
        }
        $rows = $this->review->rows($kind, $record);
        if ($rows->isEmpty() || ! $rows->every(fn ($row) => $row->status === 'pending')) {
            throw new \RuntimeException('Compact review requires a new pending request.');
        }
        $text = $this->card($kind, $record, $this->review->snapshot($kind, $rows));
        $this->assertLength($text);
        $key = $kind.':created:'.$record->id;
        $context = [
            'operation_key' => $key, 'notification_type' => 'structured_'.$kind, 'bot' => 'work',
            'source_type' => class_basename($record), 'source_id' => $record->id,
            'chat_id' => $chat, 'message_thread_id' => $thread !== '' ? $thread : null,
        ];
        if (! $this->monitor->reserveExpected($context)) {
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)
                ->where('notification_type', 'structured_'.$kind)->where('status', 'sent')
                ->where('chat_id', $chat)->where('message_thread_id', $thread !== '' ? $thread : null)->first();
            if ($receipt?->telegram_message_id) {
                $this->rememberSource($record, $chat, $thread, $receipt->telegram_message_id);
            }
            return;
        }
        try {
            $id = $this->monitor->withContext($context, fn () => $this->telegram->sendMessage(
                $chat, $text, $thread !== '' ? $thread : null, replyMarkup: $this->keyboard($kind, $record->id, true),
            ));
        } catch (TelegramRequestNotSent $exception) {
            TelegramOutboundOperation::query()->where('operation_key', $key)->whereIn('status', ['pending', 'uncertain'])->update(['status' => 'expected']);
            throw $exception;
        }
        if (! $id) {
            throw new \RuntimeException('Structured source delivery was not confirmed.');
        }
        $this->rememberSource($record, $chat, $thread, $id);
    }

    private function rememberSource(Model $record, string $chat, string $thread, int $id): void
    {
        $record->newQuery()->whereKey($record->id)->whereNull('telegram_review_version')->update([
            'telegram_source_chat_id' => $chat,
            'telegram_source_message_id' => $id,
            'telegram_source_thread_id' => $thread !== '' ? (int) $thread : null,
            'telegram_review_version' => 0,
        ]);
    }

    public function deliver(StructuredRequestDecision $decision): void
    {
        $key = 'structured:'.$decision->id.':result';
        if ($decision->delivery_status === 'uncertain') {
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)->where('status', 'sent')->first();
            if ($receipt?->telegram_message_id) {
                $this->markDelivered($decision, $receipt->telegram_message_id);
            }
            return;
        }
        if ($decision->delivery_status !== 'pending') {
            return;
        }
        if (! $decision->recipient_chat_id || ! filled(config('services.telegram.bot_token'))) {
            $decision->update(['delivery_status' => 'failed']);
            return;
        }
        if (StructuredRequestDecision::query()->whereKey($decision->id)->where('delivery_status', 'pending')
            ->update(['delivery_status' => 'uncertain']) !== 1) {
            return;
        }
        $context = [
            'operation_key' => $key, 'notification_type' => 'structured_'.$decision->kind.'_result', 'bot' => 'work',
            'source_type' => class_basename(StructuredRequestReview::model($decision->kind)),
            'source_id' => $decision->request_id, 'chat_id' => $decision->recipient_chat_id,
        ];
        $this->monitor->expect($context);
        try {
            $id = $this->monitor->withContext($context, fn () => $this->telegram->sendMessage(
                $decision->recipient_chat_id, $decision->result_html,
                replyMarkup: ['inline_keyboard' => [[['text' => 'Мои заявки', 'url' => route('page-profile.applications')]]]],
            ));
            if ($id) {
                $this->markDelivered($decision, $id);
                return;
            }
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)->first();
            if ($receipt?->status === 'failed' && $receipt->error_code >= 400 && $receipt->error_code < 500 && $receipt->error_code !== 408) {
                $decision->update(['delivery_status' => 'failed']);
            }
        } catch (TelegramRequestNotSent $exception) {
            $decision->update(['delivery_status' => 'pending']);
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Structured result delivery uncertain.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }
    }

    private function markDelivered(StructuredRequestDecision $decision, int $id): void
    {
        $decision->update(['delivery_status' => 'sent', 'telegram_message_id' => $id]);
        $model = StructuredRequestReview::model($decision->kind);
        $model::query()->whereKey($decision->request_id)->where('telegram_review_version', $decision->version)
            ->whereNull('notified_at')->update(['notified_at' => now()]);
    }

    public function syncSource(StructuredRequestDecision $decision): bool
    {
        $decision->refresh();
        [$chat, $thread] = $this->review->destination($decision->kind);
        $model = StructuredRequestReview::model($decision->kind);
        $record = $model::query()->find($decision->request_id);
        if (! $record || ! $this->review->hasSource($decision->kind, $record)
            || $decision->source_chat_id !== $chat || (int) $record->telegram_source_message_id !== $decision->source_message_id) {
            $decision->update(['card_sync_status' => 'failed', 'card_sync_attempts' => 5]);
            return false;
        }
        $context = [
            'operation_key' => 'structured:'.$decision->id.':edit:'.$decision->delivery_status,
            'notification_type' => 'structured_'.$decision->kind, 'method' => 'editMessageText', 'bot' => 'work',
            'source_type' => class_basename($record), 'source_id' => $record->id,
            'chat_id' => $chat, 'message_thread_id' => $thread !== '' ? $thread : null,
        ];
        $delivery = match ($decision->delivery_status) {
            'sent' => '✓ Ответ отправлен сотруднику',
            'failed' => '⚠️ Ответ сохранён, но не доставлен',
            'uncertain' => '⚠️ Результат доставки не подтверждён',
            default => '⏳ Ответ ожидает отправки',
        };
        $this->monitor->expect($context);
        try {
            $edited = $this->monitor->withContext($context, fn () => $this->telegram->editMessage(
                $chat, $decision->source_message_id, [], $decision->card_html."\n".$delivery,
                $this->keyboard($decision->kind, $record->id, false), forceLegacy: true,
            ));
        } catch (\Throwable $exception) {
            $edited = false;
            Log::warning('Structured card sync failed.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }
        $decision->update(['card_sync_status' => $edited ? 'synced' : 'failed', 'card_sync_attempts' => min(5, $decision->card_sync_attempts + 1)]);
        return $edited;
    }
}

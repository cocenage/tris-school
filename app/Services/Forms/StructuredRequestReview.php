<?php

namespace App\Services\Forms;

use App\Jobs\DeliverStructuredRequestDecision;
use App\Models\DayOffRequest;
use App\Models\InventoryRequest;
use App\Models\StructuredRequestDecision;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RolePreviewContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StructuredRequestReview
{
    public const PICKUP_ADDRESS = 'Via Luigi Calamatta 14, Milano';
    public const PICKUP_MESSAGE = "Ждём тебя в нашем офисе для получения рабочего инвентаря.\n📍 Via Luigi Calamatta 14, Milano";
    public const PICKUP_URL = 'https://www.google.com/maps/search/?api=1&query=Via+Luigi+Calamatta+14%2C+Milano%2C+Italia';

    public static function model(string $kind): string
    {
        return match ($kind) {
            'day_off' => DayOffRequest::class,
            'vacation' => VacationRequest::class,
            'inventory' => InventoryRequest::class,
            default => abort(404),
        };
    }

    public function enabled(string $kind): bool
    {
        return (bool) config('services.staff_forms.'.$kind.'_telegram_review_enabled', false);
    }

    public function canReview(?User $user): bool
    {
        return $user !== null && $user->is_active === true && $user->isApproved()
            && in_array($user->role, ['admin', 'supervisor'], true);
    }

    public function destination(string $kind): array
    {
        return match ($kind) {
            'day_off' => [(string) config('services.telegram.chat_id_formweekend'), (string) config('services.telegram.thread_id_formweekend')],
            'vacation' => [(string) config('services.telegram.chat_id_vacation'), (string) config('services.telegram.thread_id_vacation')],
            'inventory' => [(string) config('services.staff_forms.chat_id'), (string) config('services.staff_forms.inventory_thread_id')],
            default => abort(404),
        };
    }

    public function label(string $kind): string
    {
        return match ($kind) {
            'day_off' => '🌿 Заявка на выходные',
            'vacation' => '🏖 Заявка на отпуск',
            'inventory' => '📦 Запрос инвентаря',
            default => abort(404),
        };
    }

    public function resourceUrl(string $kind, int $id): string
    {
        $path = match ($kind) {
            'day_off' => 'day-off-requests',
            'vacation' => 'vacation-requests',
            'inventory' => 'inventory-requests',
            default => abort(404),
        };
        return url('/admin/'.$path.'/'.$id);
    }

    public function rows(string $kind, Model $record, bool $lock = false): Collection
    {
        $query = $kind === 'inventory' ? $record->lines() : $record->days();
        $query->reorder('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->get();
    }

    public function find(string $kind, int $id): Model
    {
        $model = self::model($kind);
        return $model::query()->findOrFail($id);
    }

    public function hasSource(string $kind, Model $record): bool
    {
        [$chat, $thread] = $this->destination($kind);
        return $record->telegram_review_version !== null
            && filled($record->telegram_source_message_id)
            && $chat !== ''
            && (string) $record->telegram_source_chat_id === $chat
            && (string) ($record->telegram_source_thread_id ?? '') === $thread;
    }

    public function canOpen(string $kind, Model $record, ?User $actor, bool $telegram = true): bool
    {
        return (! $telegram || $this->enabled($kind)) && $this->canReview($actor)
            && ($telegram || $actor->isAdmin())
            && $this->hasSource($kind, $record)
            && (int) $record->telegram_review_version === 0
            && $this->rows($kind, $record)->contains('status', 'pending');
    }

    public function fingerprint(string $kind, Model $record, ?Collection $rows = null): string
    {
        $rows ??= $this->rows($kind, $record);
        return hash('sha256', json_encode([
            $record->id, $record->telegram_review_version, $record->status, $record->getRawOriginal('updated_at'),
            $rows->map(fn ($row) => $row->getAttributes())->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function snapshot(string $kind, Collection $rows): array
    {
        return $rows->sortBy($kind === 'inventory' ? 'id' : 'date')->map(function ($row) use ($kind): array {
            return [
                'id' => (int) $row->id,
                'label' => $kind === 'inventory'
                    ? trim($row->item_name.($row->variant_label ? ' · '.$row->variant_label : ''))
                    : $row->date->locale('ru')->translatedFormat('d.m.Y (l)'),
                'status' => (string) $row->status,
                'requested' => $kind === 'inventory' ? (int) $row->requested_qty : null,
                'approved' => $kind === 'inventory' ? (int) ($row->approved_qty ?? $row->issued_qty ?? 0) : null,
                'reason' => (string) $row->admin_comment,
            ];
        })->values()->all();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }

    public function decide(string $kind, Model $record, User $actor, string $fingerprint, array $items, ?string $comment, bool $telegram = true): StructuredRequestDecision
    {
        app(RolePreviewContext::class)->assertWritable();
        abort_unless($this->canReview($actor) && ($telegram || $actor->isAdmin()), 403);
        $comment = trim((string) $comment);
        if (mb_strlen($comment) > 1800) {
            $this->invalid('Общий комментарий не должен превышать 1800 символов.');
        }

        $decision = DB::transaction(function () use ($kind, $record, $actor, $fingerprint, $items, $comment, $telegram): StructuredRequestDecision {
            // Lock children before the parent, consistent with the historical day-off callback.
            $rows = $this->rows($kind, $record, true);
            $current = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $freshActor = User::query()->find($actor->id);
            abort_unless($this->canReview($freshActor) && ($telegram || $freshActor->isAdmin()), 403);
            if (! $this->canOpen($kind, $current, $freshActor, $telegram)
                || ! hash_equals($this->fingerprint($kind, $current, $rows), $fingerprint)) {
                $this->invalid('Заявка уже изменена. Откройте её заново.');
            }

            $pending = $rows->where('status', 'pending')->keyBy('id');
            $expected = $pending->keys()->map(fn ($id) => (string) $id)->sort()->values()->all();
            $actual = collect(array_keys($items))->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($expected !== $actual || $pending->isEmpty()) {
                $this->invalid('Рассмотрите все оставшиеся даты или позиции.');
            }

            $now = now();
            foreach ($pending as $id => $row) {
                $entry = $items[$id];
                if (! is_array($entry) || (isset($entry['reason']) && ! is_string($entry['reason']))) {
                    $this->invalid('Некорректное решение.');
                }
                $reason = trim((string) ($entry['reason'] ?? ''));
                if (mb_strlen($reason) > 600) {
                    $this->invalid('Причина отказа — не более 600 символов.');
                }
                if ($kind === 'inventory') {
                    $qty = filter_var($entry['qty'] ?? null, FILTER_VALIDATE_INT);
                    $requested = (int) $row->requested_qty;
                    if ($qty === false || $qty < 0 || $qty > $requested || $requested < 1) {
                        $this->invalid('Количество должно быть от нуля до запрошенного.');
                    }
                    if ($qty < $requested && $reason === '') {
                        $this->invalid('Укажите причину для каждой позиции с уменьшенным количеством.');
                    }
                    $row->forceFill([
                        'approved_qty' => $qty,
                        'status' => $qty === 0 ? 'rejected' : ($qty === $requested ? 'approved' : 'partially_approved'),
                        'admin_comment' => $qty < $requested ? $reason : null,
                        'processed_by' => $actor->id, 'processed_at' => $now,
                    ])->save();
                } else {
                    $choice = $entry['choice'] ?? null;
                    if (! in_array($choice, ['approved', 'rejected'], true)) {
                        $this->invalid('Выберите решение для каждой даты.');
                    }
                    if ($choice === 'rejected' && $reason === '') {
                        $this->invalid('Укажите причину для каждой отклонённой даты.');
                    }
                    $row->forceFill([
                        'status' => $choice,
                        'admin_comment' => $choice === 'rejected' ? $reason : null,
                        'reviewed_at' => $now, 'reviewed_by' => $actor->id,
                    ])->save();
                }
            }

            $statuses = $rows->pluck('status');
            $allApproved = $statuses->every(fn ($s) => in_array($s, ['approved', 'issued'], true));
            $allRejected = $statuses->every(fn ($s) => in_array($s, ['rejected', 'cancelled'], true));
            $status = $allApproved ? 'approved' : ($allRejected ? 'rejected' : 'partially_approved');
            if ($kind === 'inventory') {
                if (! $allRejected && $comment === '') {
                    $this->invalid('Укажите сообщение о получении инвентаря.');
                }
                if ($allRejected) {
                    $comment = '';
                }
            }

            $current->forceFill([
                'status' => $status,
                'admin_comment' => $comment !== '' ? $comment : null,
                'telegram_review_version' => 1,
                'answered_at' => $now, 'answer_seen_at' => null,
                ...($kind === 'inventory'
                    ? ['processed_at' => $now, 'processed_by' => $actor->id]
                    : ['reviewed_at' => $now, 'reviewed_by' => $actor->id]),
            ])->save();
            $current->loadMissing('user');
            $snapshot = $this->snapshot($kind, $rows);
            $messages = app(TelegramStructuredRequestService::class);
            $result = $messages->result($kind, $current, $snapshot);
            $card = $messages->card($kind, $current, $snapshot, $actor);
            // Keep one message per recipient; reject oversized text before committing any changes.
            $messages->assertLength($result);
            $messages->assertLength($card, 3900);

            return StructuredRequestDecision::create([
                'kind' => $kind, 'request_id' => $current->id, 'reviewer_id' => $actor->id,
                'version' => 1, 'status' => $status,
                'snapshot' => ['items' => $snapshot, 'comment' => $comment, 'surface' => $telegram ? 'telegram' : 'admin'],
                'result_html' => $result, 'card_html' => $card,
                'source_chat_id' => $current->telegram_source_chat_id,
                'source_message_id' => $current->telegram_source_message_id,
                'recipient_chat_id' => $current->user?->telegram_id,
            ]);
        }, 3);

        try {
            DeliverStructuredRequestDecision::dispatch($decision->id)->afterCommit();
        } catch (\Throwable $exception) {
            Log::warning('Structured decision queue dispatch failed.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }

        return $decision;
    }

    public static function pickupHtml(string $text): string
    {
        return str_replace(e(self::PICKUP_ADDRESS), '<a href="'.e(self::PICKUP_URL).'">'.e(self::PICKUP_ADDRESS).'</a>', e($text));
    }
}

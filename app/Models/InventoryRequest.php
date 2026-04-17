<?php

namespace App\Models;

use App\Services\Forms\InventoryRequestTelegramService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class InventoryRequest extends Model
{
    protected $fillable = [
        'user_id',
        'comment',
        'status',
        'admin_comment',
        'submitted_at',
        'processed_at',
        'processed_by',
        'notified_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    protected $appends = [
        'user_name',
    ];

    public function getUserNameAttribute(): string
    {
        return $this->user?->name ?? '—';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryRequestLine::class);
    }

    public function recalculateStatus(): void
    {
        $statuses = $this->lines()->pluck('status');

        if ($statuses->isEmpty()) {
            $this->update([
                'status' => 'pending',
                'processed_at' => null,
                'processed_by' => null,
            ]);

            return;
        }

        $issued = $statuses->contains('issued');
        $partial = $statuses->contains('partially_issued');
        $cancelled = $statuses->contains('cancelled');
        $pending = $statuses->contains('pending');

        $status = match (true) {
            $pending && ! $issued && ! $partial && ! $cancelled => 'pending',
            ! $pending && $issued && ! $partial && ! $cancelled => 'issued',
            ! $pending && ! $issued && ! $partial && $cancelled => 'cancelled',
            ! $pending && ($partial || ($issued && $cancelled) || ($issued && $partial) || ($partial && $cancelled)) => 'partially_issued',
            $pending && ($issued || $partial || $cancelled) => 'pending',
            default => 'pending',
        };

        $isFinal = in_array($status, ['issued', 'partially_issued', 'cancelled'], true) && ! $pending;

        $this->update([
            'status' => $status,
            'processed_at' => $isFinal ? now() : null,
            'processed_by' => $isFinal ? auth()->id() : null,
        ]);
    }

    public function allLinesProcessed(): bool
    {
        return $this->lines()
            ->where('status', 'pending')
            ->doesntExist();
    }

    public function resetNotification(): void
    {
        $this->update([
            'notified_at' => null,
        ]);
    }

    public function notifyUserIfFinal(): void
    {
        $this->loadMissing(['user', 'lines']);

        Log::info('Inventory notify check', [
            'request_id' => $this->id,
            'status' => $this->status,
            'notified_at' => $this->notified_at,
            'all_processed' => $this->allLinesProcessed(),
            'lines_statuses' => $this->lines->pluck('status')->toArray(),
            'telegram_id' => $this->user?->telegram_id,
        ]);

        if ($this->notified_at) {
            Log::info('Inventory notify skipped: already notified', [
                'request_id' => $this->id,
            ]);

            return;
        }

        if (! $this->allLinesProcessed()) {
            Log::info('Inventory notify skipped: not all processed', [
                'request_id' => $this->id,
            ]);

            return;
        }

        if (! in_array($this->status, ['issued', 'partially_issued', 'cancelled'], true)) {
            Log::info('Inventory notify skipped: wrong final status', [
                'request_id' => $this->id,
                'status' => $this->status,
            ]);

            return;
        }

        Log::info('Inventory notify sending', [
            'request_id' => $this->id,
        ]);

        app(InventoryRequestTelegramService::class)->sendResult($this);

        $this->update([
            'notified_at' => now(),
        ]);
    }

    public function syncStatusAndNotify(): void
    {
        $this->recalculateStatus();

        $this->refresh();

        $this->load([
            'user',
            'lines',
        ]);

        $this->notifyUserIfFinal();
    }
}
<?php

namespace App\Models;

use App\Services\Forms\DayOffRequestTelegramService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class DayOffRequest extends Model
{
    protected $fillable = [
        'user_id',
        'reason',
        'status',
        'admin_comment',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'notified_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function days(): HasMany
    {
        return $this->hasMany(DayOffRequestDay::class);
    }

    public function recalculateStatus(): void
    {
        $statuses = $this->days()->pluck('status');

        if ($statuses->isEmpty()) {
            $this->update([
                'status' => 'pending',
            ]);

            return;
        }

        $approved = $statuses->contains('approved');
        $rejected = $statuses->contains('rejected');
        $pending = $statuses->contains('pending');

        $status = match (true) {
            $pending && ! $approved && ! $rejected => 'pending',
            ! $pending && $approved && ! $rejected => 'approved',
            ! $pending && ! $approved && $rejected => 'rejected',
            ! $pending && $approved && $rejected => 'partially_approved',
            $pending && ($approved || $rejected) => 'pending',
            default => 'pending',
        };

        $this->update([
            'status' => $status,
        ]);
    }

    public function allDaysReviewed(): bool
    {
        return $this->days()
            ->whereNotIn('status', ['approved', 'rejected'])
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
        $this->loadMissing(['user', 'days']);

        Log::info('DayOff notify check', [
            'request_id' => $this->id,
            'status' => $this->status,
            'notified_at' => $this->notified_at,
            'all_reviewed' => $this->allDaysReviewed(),
            'days_statuses' => $this->days->pluck('status')->toArray(),
            'telegram_id' => $this->user?->telegram_id,
        ]);

        if ($this->notified_at) {
            Log::info('DayOff notify skipped: already notified', [
                'request_id' => $this->id,
            ]);
            return;
        }

        if (! $this->allDaysReviewed()) {
            Log::info('DayOff notify skipped: not all reviewed', [
                'request_id' => $this->id,
            ]);
            return;
        }

        if (! in_array($this->status, ['approved', 'rejected', 'partially_approved'], true)) {
            Log::info('DayOff notify skipped: wrong final status', [
                'request_id' => $this->id,
                'status' => $this->status,
            ]);
            return;
        }

        Log::info('DayOff notify sending', [
            'request_id' => $this->id,
        ]);

        app(DayOffRequestTelegramService::class)->sendResult($this);

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
            'days',
        ]);

        $this->notifyUserIfFinal();
    }
}
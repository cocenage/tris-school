<?php

namespace App\Console\Commands;

use App\Jobs\SendControlCompletionNotification;
use App\Models\ControlCompletionNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchPendingControlCompletionNotifications extends Command
{
    protected $signature = 'controls:dispatch-completion-notifications {--limit=100}';

    protected $description = 'Dispatch pending control completion notifications to the work bot queue';

    public function handle(): int
    {
        $now = now();
        $staleBefore = $now->copy()->subMinutes(15);

        ControlCompletionNotification::query()
            ->where('status', 'sending')
            ->where('last_attempt_at', '<=', $staleBefore)
            ->update([
                'status' => 'uncertain',
                'last_error' => 'worker_interrupted',
                'updated_at' => $now,
            ]);

        $limit = max(1, min(500, (int) $this->option('limit')));
        $eligible = ControlCompletionNotification::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', $now->copy()->subMinutes(5)))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $dispatched = 0;
        foreach ($eligible as $notification) {
            $claimed = ControlCompletionNotification::query()
                ->whereKey($notification->id)
                ->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', $now->copy()->subMinutes(5)))
                ->update(['last_dispatched_at' => $now, 'updated_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                SendControlCompletionNotification::dispatch((int) $notification->id);
                $dispatched++;
            } catch (Throwable $exception) {
                Log::warning('Pending control completion notification dispatch failed.', [
                    'notification_id' => $notification->id,
                    'error' => class_basename($exception),
                ]);
            }
        }

        $this->info("Dispatched {$dispatched} control completion notification(s).");

        return self::SUCCESS;
    }
}

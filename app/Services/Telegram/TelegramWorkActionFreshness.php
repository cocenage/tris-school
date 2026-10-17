<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Log;

class TelegramWorkActionFreshness
{
    public function messageIsCurrent(array $message): bool
    {
        $timestamp = $message['edit_date'] ?? $message['date'] ?? null;

        if (! is_numeric($timestamp)) {
            return false;
        }

        $now = now()->timestamp;
        $maxAgeSeconds = max(1, (int) config('services.telegram.work_action_max_age_minutes', 30)) * 60;

        return (int) $timestamp >= $now - $maxAgeSeconds
            && (int) $timestamp <= $now + 60;
    }

    public function callbackRequiresReconciliation(): bool
    {
        // Callback queries have no trustworthy press timestamp. During backlog
        // recovery, defer their state changes until the queue has been drained.
        return (bool) config('services.telegram.work_backlog_callback_guard_enabled', true);
    }

    public function recordManualReconciliation(array $update, string $reason): void
    {
        $data = (string) data_get($update, 'callback_query.data', '');
        $parts = explode(':', $data, 3);

        Log::warning('telegram_work_manual_reconciliation_required', [
            'update_id' => is_numeric($update['update_id'] ?? null) ? (int) $update['update_id'] : null,
            'reason' => $reason,
            'callback_type' => $data !== '' ? ($parts[0] ?? null) : null,
            'callback_action' => $data !== '' ? ($parts[1] ?? null) : null,
            'callback_target_id' => is_numeric($parts[2] ?? null) ? (int) $parts[2] : null,
        ]);
    }
}

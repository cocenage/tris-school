<?php

namespace App\Services\Controls;

use App\Jobs\SendControlCompletionNotification;
use App\Models\ControlCompletionNotification;
use App\Models\ControlResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ControlCompletionNotificationService
{
    public function queueFor(ControlResponse $response): ControlCompletionNotification
    {
        $response->loadMissing('cleaner');
        $recipient = $response->cleaner;
        $eligible = $recipient instanceof User
            && in_array($recipient->role, ['cleaner', 'supervisor'], true);
        $hasTelegram = $eligible && filled($recipient->telegram_id);

        $notification = ControlCompletionNotification::query()->firstOrCreate(
            ['control_response_id' => $response->getKey()],
            [
                'recipient_user_id' => $eligible ? $recipient->getKey() : null,
                'status' => $hasTelegram ? 'pending' : 'skipped',
                'last_error' => $eligible ? ($hasTelegram ? null : 'recipient_telegram_missing') : 'recipient_not_eligible',
            ],
        );

        if ($notification->wasRecentlyCreated && $notification->status === 'pending') {
            $this->dispatchAfterCommit((int) $notification->getKey());
        }

        return $notification;
    }

    public function miniAppUrl(int $responseId): ?string
    {
        $botUsername = trim((string) config('services.telegram.bot_username'));
        $botUsername = ltrim($botUsername, '@');

        if ($botUsername === '' || ! preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername)) {
            return null;
        }

        return 'https://t.me/'.$botUsername.'?startapp='.rawurlencode('control_result_'.$responseId);
    }

    private function dispatchAfterCommit(int $notificationId): void
    {
        $dispatch = static function () use ($notificationId): void {
            $notification = ControlCompletionNotification::query()
                ->whereKey($notificationId)
                ->where('status', 'pending')
                ->first();

            if (! $notification) {
                return;
            }

            try {
                $notification->forceFill(['last_dispatched_at' => now()])->saveQuietly();
                SendControlCompletionNotification::dispatch($notificationId);
            } catch (Throwable $exception) {
                Log::warning('Control completion notification could not be queued.', [
                    'notification_id' => $notificationId,
                    'error' => class_basename($exception),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}

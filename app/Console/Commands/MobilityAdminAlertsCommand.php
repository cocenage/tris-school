<?php

namespace App\Console\Commands;

use App\Models\MobilityAlert;
use App\Models\MobilityAlertMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilityAdminAlertsCommand extends Command
{
    protected $signature = 'mobility:admin-alerts {--dry-run}';

    protected $description = 'Send new mobility alerts to admin Telegram chats';

    public function handle(): int
    {
        $alerts = MobilityAlert::query()
            ->whereIn('risk', ['critical', 'high', 'medium'])
            ->where(function ($query) {
                $query
                    ->where('type', 'strike')
                    ->orWhere('title', 'ilike', '%sciopero%')
                    ->orWhere('title', 'ilike', '%strike%')
                    ->orWhere('description', 'ilike', '%sciopero%')
                    ->orWhere('description', 'ilike', '%strike%');
            })
            ->whereDoesntHave('messages', function ($query) {
                $query
                    ->where('message_type', 'admin_alert')
                    ->whereNull('deleted_at');
            })
            ->orderBy('starts_at')
            ->get();

        if ($alerts->isEmpty()) {
            $this->info('No new admin mobility alerts.');

            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $message = $this->buildMessage($alert);

            if ($this->option('dry-run')) {
                $this->line('');
                $this->line('===== DRY RUN ADMIN ALERT =====');
                $this->line($message);
                $this->line('===============================');
                $this->line('');

                continue;
            }

            $this->sendTelegram($alert, $message);
        }

        $this->info('Admin mobility alerts completed.');

        return self::SUCCESS;
    }

    protected function buildMessage(MobilityAlert $alert): string
    {
        $date = $alert->starts_at
            ? $alert->starts_at->format('d.m.Y')
            : 'дата не указана';

        $text = "🚨 <b>Важное уведомление</b>\n\n";
        $text .= "<b>{$this->escape($alert->title)}</b>\n\n";
        $text .= "Дата: {$date}\n";
        $text .= "Риск: {$this->escape($alert->risk ?? 'unknown')}\n";
        $text .= "Тип: {$this->escape($alert->type ?? 'unknown')}\n";
        $text .= "Источник: {$this->escape($alert->source ?? 'unknown')}\n";

        if (! empty($alert->district)) {
            $text .= "Район/линия: {$this->escape($alert->district)}\n";
        }

        if (! empty($alert->description)) {
            $text .= "\n{$this->escape($alert->description)}\n";
        }

        if (! empty($alert->url)) {
            $text .= "\n<a href=\"{$this->escape($alert->url)}\">Открыть источник</a>";
        }

        return trim($text);
    }

    protected function adminTargets(): array
    {
        $raw = config('services.telegram.mobility_admin_targets');

        if (! $raw) {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->map(function ($item) {
                [$chatId, $threadId] = array_pad(
                    explode(':', $item, 2),
                    2,
                    null
                );

                return [
                    'chat_id' => trim($chatId),
                    'thread_id' => $threadId ? trim($threadId) : null,
                ];
            })
            ->filter(fn ($target) => filled($target['chat_id']))
            ->values()
            ->all();
    }

    protected function sendTelegram(MobilityAlert $alert, string $message): void
    {
        $token = config('services.telegram.analytics_bot_token');
        $targets = $this->adminTargets();

        if (! $token || empty($targets)) {
            Log::warning('Admin mobility alerts skipped: missing Telegram config', [
                'token_exists' => filled($token),
                'targets_count' => count($targets),
            ]);

            return;
        }

        foreach ($targets as $target) {
            $payload = [
                'chat_id' => $target['chat_id'],
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
            ];

            if (! empty($target['thread_id'])) {
                $payload['message_thread_id'] = $target['thread_id'];
            }

            try {
                $response = Http::timeout(30)
                    ->retry(3, 2000)
                    ->withoutVerifying()
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            } catch (\Throwable $e) {
                Log::warning('Admin mobility alert telegram connection failed', [
                    'alert_id' => $alert->id,
                    'chat_id' => $target['chat_id'],
                    'thread_id' => $target['thread_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Admin mobility alert telegram failed', [
                    'alert_id' => $alert->id,
                    'chat_id' => $target['chat_id'],
                    'thread_id' => $target['thread_id'] ?? null,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $telegramMessageId = data_get($response->json(), 'result.message_id');

            if ($telegramMessageId) {
                MobilityAlertMessage::create([
                    'mobility_alert_id' => $alert->id,
                    'message_type' => 'admin_alert',
                    'chat_id' => (string) $target['chat_id'],
                    'thread_id' => $target['thread_id'] ? (string) $target['thread_id'] : null,
                    'telegram_message_id' => (string) $telegramMessageId,
                    'text' => $message,
                    'sent_at' => now(),
                ]);
            }

            usleep(500000);
        }
    }

    protected function escape(?string $value): string
    {
        return e($value ?? '');
    }
}
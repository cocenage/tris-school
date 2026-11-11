<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramPollingHealthMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TelegramPollingHealthCommand extends Command
{
    protected $signature = 'telegram:polling-health';

    protected $description = 'Show recent, secret-free health for Telegram polling clients';

    public function handle(TelegramPollingHealthMonitor $monitor): int
    {
        $rows = [];

        foreach ($monitor->all() as $snapshot) {
            $rows[] = [
                $snapshot['label'],
                $this->labelForStatus($snapshot['status']),
                $this->formatTimestamp($snapshot['last_success_at']),
                $snapshot['last_failure_stage'] ?? '—',
                $snapshot['last_connection_error_code'] ?? '—',
                $snapshot['recent_issues'],
                $snapshot['last_duration_ms'] === null ? '—' : $snapshot['last_duration_ms'].' ms',
            ];
        }

        $this->table(['Bot', 'Состояние', 'Последний успех (Europe/Rome)', 'Последний сбой', 'cURL code', 'Проблемы за 15 мин', 'Длительность'], $rows);

        return self::SUCCESS;
    }

    private function labelForStatus(string $status): string
    {
        return match ($status) {
            'working' => 'Работает',
            'partially_degraded' => 'Частично деградировал',
            'unavailable' => 'Недоступен',
            default => 'Нет данных',
        };
    }

    private function formatTimestamp(?int $timestamp): string
    {
        return $timestamp === null
            ? '—'
            : CarbonImmutable::createFromTimestamp($timestamp, 'UTC')
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
    }
}

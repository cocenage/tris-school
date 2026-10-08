<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarSummaryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class TelegramTomorrowSummaryPreviewCommand extends Command
{
    protected $signature = 'telegram:tomorrow-summary-preview {--date= : Target date in Europe/Rome, YYYY-MM-DD} {--json : Print structured preview}';

    protected $description = 'Preview the existing staff summary for tomorrow without Telegram delivery';

    public function handle(CalendarSummaryService $calendarSummary, SendTomorrowCalendarEventsNotification $calendarNotification): int
    {
        $timezone = 'Europe/Rome';
        $value = $this->option('date');
        $date = CarbonImmutable::now($timezone)->addDay()->startOfDay();

        if ($value !== null) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, $timezone);
            } catch (Throwable) {
                $date = false;
            }

            if (! $date || $date->format('Y-m-d') !== $value) {
                $this->error('Date must be a valid YYYY-MM-DD date.');

                return self::FAILURE;
            }
        }

        $summary = $calendarSummary->build(Carbon::instance($date->toDateTime()));

        if ($this->option('json')) {
            $this->line(json_encode([
                'date' => $date->toDateString(),
                'timezone' => $timezone,
                'shift' => $summary['shift'],
                'not_working' => collect($summary['workers']['not_working'])->map(fn ($user): array => [
                    'name' => $user->name,
                    'role' => $user->role,
                    'reason' => $user->not_working_reason,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($calendarNotification->renderStaffSummary(Carbon::instance($date->toDateTime()), $summary));
        }

        return self::SUCCESS;
    }
}

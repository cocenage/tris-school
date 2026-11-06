<?php

namespace App\Observers;

use App\Jobs\RefreshCalendarSummaryMessage;
use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Models\EmployeeCalendarAdjustment;
use App\Models\EmployeeWorkScheduleVersion;
use App\Models\TelegramOutboundOperation;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CalendarSummarySourceObserver
{
    private const USER_CALENDAR_ATTRIBUTES = [
        'name', 'role', 'status', 'is_active', 'weekend_days', 'work_started_at',
    ];

    public function created(Model $model): void
    {
        if ($model instanceof DayOffRequestDay || $model instanceof VacationRequestDay) {
            $this->dispatchDates([$model->date]);

            return;
        }

        if ($model instanceof EmployeeCalendarAdjustment) {
            $this->dispatchDates($this->adjustmentDates($model));

            return;
        }

        if ($model instanceof EmployeeWorkScheduleVersion) {
            $this->dispatchDates($this->nearTermDates($model->effective_from));

            return;
        }

        if ($model instanceof User && $this->hasCalendarRole($model)) {
            $this->dispatchDates($this->nearTermDates());
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof DayOffRequestDay || $model instanceof VacationRequestDay) {
            if (! $model->wasChanged(['date', 'user_id', 'status'])) {
                return;
            }

            $this->dispatchDates([
                $model->getOriginal('date'),
                $model->date,
            ]);

            return;
        }

        if ($model instanceof DayOffRequest || $model instanceof VacationRequest) {
            if ($model->wasChanged('status')) {
                $this->dispatchDates($model->days()->pluck('date')->all());
            }

            return;
        }

        if ($model instanceof User
            && $model->wasChanged(self::USER_CALENDAR_ATTRIBUTES)
            && $this->hasCalendarRole($model, $model->getOriginal('role'))) {
            $this->dispatchDates($this->nearTermDates());
        }
    }

    public function deleting(Model $model): void
    {
        if ($model instanceof DayOffRequest || $model instanceof VacationRequest) {
            $model->setRelation('calendarSummaryAffectedDates', $model->days()->pluck('date')->all());
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof DayOffRequest || $model instanceof VacationRequest) {
            $this->dispatchDates($model->getRelation('calendarSummaryAffectedDates') ?? []);

            return;
        }

        if ($model instanceof DayOffRequestDay || $model instanceof VacationRequestDay) {
            $this->dispatchDates([$model->date]);

            return;
        }

        if ($model instanceof User && $this->hasCalendarRole($model)) {
            $this->dispatchDates($this->nearTermDates());
        }
    }

    private function dispatchDates(array $dates): void
    {
        $dateKeys = collect($dates)
            ->filter(fn (mixed $date): bool => filled($date))
            ->map(fn (mixed $date): ?string => $this->normalizeDate($date))
            ->filter()
            ->unique()
            ->values();

        if ($dateKeys->isEmpty()) {
            return;
        }

        try {
            $chatId = config('services.telegram.chat_id_calendar');

            if (! filled($chatId) || ! Schema::hasTable('telegram_outbound_operations')) {
                return;
            }

            $operationKeys = $dateKeys->map(fn (string $date): string => 'calendar:tomorrow:'.$date)->all();
            $dateKeys = TelegramOutboundOperation::query()
                ->whereIn('operation_key', $operationKeys)
                ->where('notification_type', 'calendar')
                ->where('bot', 'work')
                ->where('chat_id', (string) $chatId)
                ->whereNotNull('telegram_message_id')
                ->pluck('source_id')
                ->map(fn (mixed $date): ?string => $this->normalizeDate($date))
                ->filter()
                ->unique()
                ->values();
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return;
        }

        foreach ($dateKeys as $date) {
            try {
                DB::afterCommit(fn () => RefreshCalendarSummaryMessage::dispatch($date));
            } catch (Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    private function nearTermDates(mixed $effectiveFrom = null): array
    {
        $today = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $tomorrow = $today->addDay();
        $effectiveDate = $effectiveFrom === null ? null : $this->normalizeDate($effectiveFrom);

        return collect([$today, $tomorrow])
            ->filter(fn (CarbonImmutable $date): bool => $effectiveDate === null || $date->toDateString() >= $effectiveDate)
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->all();
    }

    private function adjustmentDates(EmployeeCalendarAdjustment $adjustment): array
    {
        $dates = collect([
            $adjustment->supersedes_request_date,
            $adjustment->replacement_date,
        ]);

        if ($adjustment->starts_on !== null) {
            $start = CarbonImmutable::parse($adjustment->starts_on)->startOfDay();
            $end = CarbonImmutable::parse($adjustment->ends_on ?? $adjustment->starts_on)->startOfDay();

            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $dates->push($date->toDateString());
            }
        }

        return $dates->all();
    }

    private function hasCalendarRole(User $user, mixed $previousRole = null): bool
    {
        return in_array($user->role, ['cleaner', 'supervisor'], true)
            || in_array($previousRole, ['cleaner', 'supervisor'], true);
    }

    private function normalizeDate(mixed $date): ?string
    {
        try {
            return CarbonImmutable::parse($date, 'Europe/Rome')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function logFailure(Throwable $exception): void
    {
        try {
            Log::warning('Calendar summary refresh could not be queued.', ['error' => class_basename($exception)]);
        } catch (Throwable) {
            // The scheduled reconciliation remains the fallback.
        }
    }
}

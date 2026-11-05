<?php

namespace App\Services\Calendar;

use App\Models\DayOffRequestDay;
use App\Models\EmployeeCalendarAdjustment;
use App\Models\EmployeeWorkScheduleVersion;
use App\Models\User;
use App\Models\VacationRequestDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeWorkCalendarManagementService
{
    public function changeSchedule(
        User $employee,
        array $newSchedule,
        CarbonInterface $effectiveFrom,
        User $actor,
        string $reason,
    ): EmployeeWorkScheduleVersion {
        $this->authorizeEdit($actor);
        $reason = $this->requiredReason($reason);
        $effectiveDate = CarbonImmutable::parse($effectiveFrom)->toDateString();
        $today = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'))->toDateString();

        if ($effectiveDate < $today) {
            throw ValidationException::withMessages(['effective_from' => 'Обычное изменение графика может вступить в силу сегодня или позднее.']);
        }

        $weekendDays = $this->validateWeekendDays($newSchedule['weekend_days'] ?? null);

        return DB::transaction(function () use ($employee, $actor, $effectiveDate, $weekendDays, $newSchedule, $reason): EmployeeWorkScheduleVersion {
            $lockedEmployee = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $previous = $lockedEmployee->workScheduleVersions()
                ->whereDate('effective_from', '<=', $effectiveDate)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $previousValues = $previous?->new_values ?? [
                'employment_start_date' => $lockedEmployee->work_started_at?->toDateString(),
                'weekend_days' => $lockedEmployee->weekend_days,
            ];
            $employmentStartDate = array_key_exists('employment_start_date', $newSchedule)
                ? $this->validateEmploymentStartDate($newSchedule['employment_start_date'])
                : ($previousValues['employment_start_date'] ?? $lockedEmployee->work_started_at?->toDateString());
            $newValues = [
                'employment_start_date' => $employmentStartDate,
                'weekend_days' => $weekendDays,
            ];

            $version = $lockedEmployee->workScheduleVersions()->create([
                'effective_from' => $effectiveDate,
                'version_type' => 'schedule_change',
                'employment_start_date' => $employmentStartDate,
                'weekend_days' => $weekendDays,
                'previous_values' => $previousValues,
                'new_values' => $newValues,
                'actor_id' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'reason' => $reason,
            ]);

            $lockedEmployee->forceFill([
                'weekend_days' => $weekendDays,
                'work_started_at' => $employmentStartDate,
            ])->save();

            return $version;
        });
    }

    public function recordProfileStartDateChange(
        User $employee,
        ?CarbonInterface $oldDate,
        CarbonInterface $newDate,
        User $actor,
        string $reason,
    ): EmployeeWorkScheduleVersion {
        if (! $actor->is($employee) && ! $actor->isAdmin() && ! $actor->canEditEmployeeCalendars()) {
            abort(403);
        }

        $reason = $this->requiredReason($reason);
        $oldValue = $oldDate ? CarbonImmutable::parse($oldDate)->toDateString() : null;
        $newValue = CarbonImmutable::parse($newDate)->toDateString();
        $effectiveDate = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'))->toDateString();

        if ($oldValue === $newValue) {
            throw ValidationException::withMessages(['work_started_at' => 'Дата начала работы не изменилась.']);
        }

        return DB::transaction(function () use ($employee, $actor, $oldValue, $newValue, $effectiveDate, $reason): EmployeeWorkScheduleVersion {
            $lockedEmployee = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $storedDate = $lockedEmployee->work_started_at?->toDateString();

            if ($storedDate !== $oldValue) {
                throw ValidationException::withMessages(['work_started_at' => 'Дата уже изменилась. Обновите профиль и повторите попытку.']);
            }

            $current = $lockedEmployee->workScheduleVersions()
                ->whereDate('effective_from', '<=', $effectiveDate)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $previousValues = $current?->new_values ?? [
                'employment_start_date' => $oldValue,
                'weekend_days' => $lockedEmployee->weekend_days,
            ];
            $weekendDays = $previousValues['weekend_days'] ?? $lockedEmployee->weekend_days;
            $newValues = [
                'employment_start_date' => $newValue,
                'weekend_days' => $weekendDays,
            ];

            $version = $lockedEmployee->workScheduleVersions()->create([
                'effective_from' => $effectiveDate,
                'version_type' => $oldValue === null ? 'employment_start_recorded' : 'employment_start_change',
                'employment_start_date' => $newValue,
                'weekend_days' => $weekendDays,
                'previous_values' => [...$previousValues, 'employment_start_date_before' => $oldValue],
                'new_values' => $newValues,
                'actor_id' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'reason' => $reason,
            ]);

            $lockedEmployee->forceFill(['work_started_at' => $newValue])->save();

            return $version;
        });
    }

    public function adjust(User $employee, array $change, User $actor, string $reason): EmployeeCalendarAdjustment
    {
        $this->authorizeEdit($actor);
        $reason = $this->requiredReason($reason);
        $operation = (string) ($change['operation'] ?? '');
        $allowedOperations = ['set_state', 'cancel_request_day', 'move_request_day', 'reverse'];

        if (! in_array($operation, $allowedOperations, true)) {
            throw ValidationException::withMessages(['operation' => 'Выберите поддерживаемый тип корректировки.']);
        }

        return DB::transaction(function () use ($employee, $change, $actor, $reason, $operation): EmployeeCalendarAdjustment {
            if ($operation === 'reverse') {
                return $this->reverseAdjustment($employee, $change, $actor, $reason);
            }

            $date = $this->validateDate($change['date'] ?? null, 'date');
            $endsOn = filled($change['ends_on'] ?? null)
                ? $this->validateDate($change['ends_on'], 'ends_on')
                : null;

            if ($endsOn && $endsOn < $date) {
                throw ValidationException::withMessages(['ends_on' => 'Дата окончания не может быть раньше начала.']);
            }

            if ($endsOn && CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($endsOn)) > 365) {
                throw ValidationException::withMessages(['ends_on' => 'Одна корректировка не может охватывать больше одного года.']);
            }

            $source = null;
            $sourceType = null;
            $sourceDate = null;
            $replacementDate = null;
            $newState = null;
            $category = $change['category'] ?? null;
            if ($category !== null && ! in_array($category, ['day_off', 'vacation', 'unpaid_leave', 'other'], true)) {
                throw ValidationException::withMessages(['category' => 'Выберите допустимую категорию календаря.']);
            }
            if (in_array($operation, ['cancel_request_day', 'move_request_day'], true)) {
                if ($endsOn !== null && $endsOn !== $date) {
                    throw ValidationException::withMessages(['ends_on' => 'Отмена или перенос применяется только к одной дате заявки.']);
                }
                [$source, $sourceType, $sourceDate] = $this->approvedRequestDay($employee, $change);
                if ($sourceDate !== $date) {
                    throw ValidationException::withMessages(['date' => 'Дата должна совпадать с выбранным днём заявки.']);
                }
                $this->ensureRequestDayNotAlreadySuperseded($employee, $sourceType, (int) $source->id);

                if ($operation === 'move_request_day') {
                    $replacementDate = $this->validateDate($change['replacement_date'] ?? null, 'replacement_date');
                    if ($replacementDate === $date) {
                        throw ValidationException::withMessages(['replacement_date' => 'Выберите другую дату для переноса.']);
                    }
                    $newState = ['replacement_state' => 'approved_absence'];
                    $category = $category ?: ($sourceType === 'vacation_request_day' ? 'vacation' : 'day_off');
                } else {
                    $category = $category ?: ($sourceType === 'vacation_request_day' ? 'vacation' : 'day_off');
                    $newState = ['state' => 'restored_schedule'];
                }
            } elseif ($operation === 'set_state') {
                $state = (string) ($change['state'] ?? '');
                if (! in_array($state, ['planned_work', 'regular_day_off', 'approved_absence', 'unknown_schedule', 'not_employed'], true)) {
                    throw ValidationException::withMessages(['state' => 'Выберите допустимое состояние календаря.']);
                }
                if ($category === 'unpaid_leave' && $state !== 'approved_absence') {
                    throw ValidationException::withMessages(['category' => 'Неоплачиваемый отпуск должен отмечаться как одобренное отсутствие.']);
                }
                $newState = ['state' => $state];
            }

            $previousProjection = app(EmployeeWorkCalendarService::class)->forEmployee(
                $employee,
                CarbonImmutable::parse($date),
                CarbonImmutable::parse($endsOn ?? $date),
            );
            $previousState = $previousProjection->count() === 1
                ? [
                    'state' => $previousProjection->first()['state'] ?? 'unknown_schedule',
                    'schedule_known' => $previousProjection->first()['schedule_known'] ?? false,
                    'pending' => $previousProjection->first()['pending'] ?? false,
                ]
                : ['states' => $previousProjection->map(fn (array $day) => [
                    'state' => $day['state'],
                    'schedule_known' => $day['schedule_known'],
                    'pending' => $day['pending'],
                ])->all()];

            return $employee->calendarAdjustments()->create([
                'starts_on' => $date,
                'ends_on' => $endsOn,
                'operation' => $operation,
                'category' => $category,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'supersedes_request_type' => $sourceType,
                'supersedes_request_day_id' => $source?->id,
                'supersedes_request_date' => $sourceDate,
                'replacement_date' => $replacementDate,
                'actor_id' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'reason' => $reason,
            ]);
        });
    }

    private function reverseAdjustment(User $employee, array $change, User $actor, string $reason): EmployeeCalendarAdjustment
    {
        $adjustmentId = (int) ($change['adjustment_id'] ?? 0);
        $original = $employee->calendarAdjustments()->whereKey($adjustmentId)->lockForUpdate()->first();

        if (! $original || $original->reverses_adjustment_id) {
            throw ValidationException::withMessages(['adjustment_id' => 'Корректировка для отмены не найдена.']);
        }

        $alreadyReversed = $employee->calendarAdjustments()
            ->where('reverses_adjustment_id', $original->id)
            ->exists();

        if ($alreadyReversed) {
            throw ValidationException::withMessages(['adjustment_id' => 'Эта корректировка уже отменена.']);
        }

        return $employee->calendarAdjustments()->create([
            'starts_on' => $original->starts_on,
            'ends_on' => $original->ends_on,
            'operation' => 'reverse',
            'category' => $original->category,
            'previous_state' => $original->new_state,
            'new_state' => null,
            'supersedes_request_type' => null,
            'supersedes_request_day_id' => null,
            'supersedes_request_date' => null,
            'replacement_date' => $original->replacement_date,
            'actor_id' => $actor->id,
            'actor_name_snapshot' => $actor->name,
            'reverses_adjustment_id' => $original->id,
            'reason' => $reason,
        ]);
    }

    private function approvedRequestDay(User $employee, array $change): array
    {
        $type = (string) ($change['request_type'] ?? '');
        $id = (int) ($change['request_day_id'] ?? 0);
        $model = match ($type) {
            'day_off_request_day' => DayOffRequestDay::class,
            'vacation_request_day' => VacationRequestDay::class,
            default => null,
        };

        if (! $model || $id < 1) {
            throw ValidationException::withMessages(['request_day_id' => 'Выберите конкретный день одобренной заявки.']);
        }

        $requestDay = $model::query()->whereKey($id)->where('user_id', $employee->id)->lockForUpdate()->first();

        if (! $requestDay || $requestDay->status !== 'approved') {
            throw ValidationException::withMessages(['request_day_id' => 'Можно корректировать только одобренный день этой заявки.']);
        }

        return [$requestDay, $type, $requestDay->date->toDateString()];
    }

    private function ensureRequestDayNotAlreadySuperseded(User $employee, string $type, int $id): void
    {
        $prior = $employee->calendarAdjustments()
            ->where('supersedes_request_type', $type)
            ->where('supersedes_request_day_id', $id)
            ->whereNull('reverses_adjustment_id')
            ->orderByDesc('id')
            ->first();

        if (! $prior) {
            return;
        }

        $reversed = $employee->calendarAdjustments()
            ->where('reverses_adjustment_id', $prior->id)
            ->exists();

        if (! $reversed) {
            throw ValidationException::withMessages(['request_day_id' => 'Этот день уже изменён отдельной календарной корректировкой.']);
        }
    }

    private function validateWeekendDays(mixed $days): array
    {
        if (! is_array($days)) {
            throw ValidationException::withMessages(['weekend_days' => 'Выберите дни регулярного выходного.']);
        }

        $normalized = [];
        foreach ($days as $day) {
            if (! is_numeric($day) || (int) $day < 1 || (int) $day > 7) {
                throw ValidationException::withMessages(['weekend_days' => 'Дни недели должны быть от 1 до 7.']);
            }
            $normalized[] = (int) $day;
        }

        return array_values(array_unique($normalized));
    }

    private function validateEmploymentStartDate(mixed $date): string
    {
        return $this->validateDate($date, 'employment_start_date');
    }

    private function validateDate(mixed $date, string $field): string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages([$field => 'Введите дату в формате ГГГГ-ММ-ДД.']);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone', 'Europe/Rome'));
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed || $parsed->toDateString() !== $date) {
            throw ValidationException::withMessages([$field => 'Введите существующую календарную дату.']);
        }

        return $date;
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину изменения.']);
        }

        return mb_substr($reason, 0, 1000);
    }

    private function authorizeEdit(User $actor): void
    {
        abort_unless($actor->isAdmin() || $actor->canEditEmployeeCalendars(), 403);
    }
}

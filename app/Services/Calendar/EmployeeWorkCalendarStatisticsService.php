<?php

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmployeeWorkCalendarStatisticsService
{
    private const METRICS = [
        'planned_workdays',
        'regular_off_days',
        'unknown_schedule_days',
        'not_employed_days',
        'approved_absence_days',
        'approved_day_off_days',
        'approved_vacation_days',
        'approved_unpaid_leave_days',
        'other_approved_absence_days',
        'pending_request_days',
        'manual_change_days',
    ];

    public function __construct(private readonly EmployeeWorkCalendarService $calendar) {}

    public function forPeriod(CarbonInterface $from, CarbonInterface $through, ?int $employeeId = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($through)->startOfDay();

        if ($end->lt($start) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['period' => 'Выберите корректный период не длиннее одного года.']);
        }

        $employees = User::query()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->orderBy('name')
            ->get();

        if ($employeeId !== null && $employees->isEmpty()) {
            abort(404);
        }

        $projection = $this->calendar->forEmployees($employees, $start, $end);
        $rows = $employees->map(function (User $employee) use ($projection): array {
            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'role' => $employee->role,
                ...$this->summarizeDays($projection->get($employee->id, collect())),
            ];
        });

        $totals = array_fill_keys(self::METRICS, 0);
        foreach ($rows as $row) {
            foreach (self::METRICS as $metric) {
                $totals[$metric] += $row[$metric];
            }
        }

        return [
            'from' => $start->toDateString(),
            'through' => $end->toDateString(),
            'employee_id' => $employeeId,
            'employee_count' => $employees->count(),
            'totals' => $totals,
            'employees' => $rows->values()->all(),
        ];
    }

    public function summarizeDays(iterable $days): array
    {
        $counts = array_fill_keys(self::METRICS, 0);

        foreach ($days as $day) {
            if ($day['state'] !== 'no_work') {
                $counts[$this->metricForDay($day)]++;
            }

            if ($day['state'] === 'approved_absence') {
                $counts['approved_absence_days']++;
            }

            if ($day['pending']) {
                $counts['pending_request_days']++;
            }

            if ($this->activeManualChanges(collect($day['sources'] ?? []))->isNotEmpty()) {
                $counts['manual_change_days']++;
            }
        }

        return $counts;
    }

    public function metricForDay(array $day): string
    {
        return match ($day['state']) {
            'planned_work' => 'planned_workdays',
            'regular_day_off' => 'regular_off_days',
            'not_employed' => 'not_employed_days',
            'approved_absence' => $this->absenceMetric(collect($day['sources'] ?? [])),
            default => 'unknown_schedule_days',
        };
    }

    private function activeManualChanges(Collection $sources): Collection
    {
        return $sources->filter(fn (array $source): bool => ($source['type'] ?? null) === 'manual_adjustment'
            && ! ($source['is_reversal'] ?? false)
            && ! ($source['superseded'] ?? false));
    }

    private function absenceMetric(Collection $sources): string
    {
        $manual = $this->activeManualChanges($sources);
        $requests = $sources->filter(fn (array $source): bool => in_array($source['type'] ?? null, ['day_off', 'vacation'], true)
            && ($source['status'] ?? null) === 'approved'
            && ! ($source['superseded'] ?? false));

        return match (true) {
            $manual->contains('category', 'unpaid_leave') => 'approved_unpaid_leave_days',
            $requests->contains('type', 'vacation') || $manual->contains('category', 'vacation') => 'approved_vacation_days',
            $requests->contains('type', 'day_off') || $manual->contains('category', 'day_off') => 'approved_day_off_days',
            default => 'other_approved_absence_days',
        };
    }
}

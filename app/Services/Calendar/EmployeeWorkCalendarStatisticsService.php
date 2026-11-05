<?php

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
            $counts = array_fill_keys(self::METRICS, 0);

            foreach ($projection->get($employee->id, collect()) as $day) {
                $sources = collect($day['sources'] ?? []);
                $activeManual = $sources->filter(fn (array $source): bool => ($source['type'] ?? null) === 'manual_adjustment'
                    && ! ($source['is_reversal'] ?? false)
                    && ! ($source['superseded'] ?? false));
                $activeRequests = $sources->filter(fn (array $source): bool => in_array($source['type'] ?? null, ['day_off', 'vacation'], true)
                    && ($source['status'] ?? null) === 'approved'
                    && ! ($source['superseded'] ?? false));

                match ($day['state']) {
                    'planned_work' => $counts['planned_workdays']++,
                    'regular_day_off' => $counts['regular_off_days']++,
                    'unknown_schedule' => $counts['unknown_schedule_days']++,
                    'not_employed' => $counts['not_employed_days']++,
                    'approved_absence' => $this->countAbsence($counts, $activeRequests, $activeManual),
                    default => null,
                };

                if ($day['pending']) {
                    $counts['pending_request_days']++;
                }

                if ($activeManual->isNotEmpty()) {
                    $counts['manual_change_days']++;
                }
            }

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'role' => $employee->role,
                ...$counts,
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

    private function countAbsence(array &$counts, $approvedRequests, $activeManual): void
    {
        $counts['approved_absence_days']++;

        // A day is assigned to one category only, even when several sources overlap.
        if ($activeManual->contains(fn (array $source): bool => ($source['category'] ?? null) === 'unpaid_leave')) {
            $counts['approved_unpaid_leave_days']++;
        } elseif ($approvedRequests->contains(fn (array $source): bool => ($source['type'] ?? null) === 'vacation')
            || $activeManual->contains(fn (array $source): bool => ($source['category'] ?? null) === 'vacation')) {
            $counts['approved_vacation_days']++;
        } elseif ($approvedRequests->contains(fn (array $source): bool => ($source['type'] ?? null) === 'day_off')
            || $activeManual->contains(fn (array $source): bool => ($source['category'] ?? null) === 'day_off')) {
            $counts['approved_day_off_days']++;
        } else {
            $counts['other_approved_absence_days']++;
        }
    }
}

<?php

namespace App\Services\Calendar;

use App\Models\DayOffRequestDay;
use App\Models\EmployeeCalendarAdjustment;
use App\Models\EmployeeWorkScheduleVersion;
use App\Models\User;
use App\Models\VacationRequestDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EmployeeWorkCalendarService
{
    public function forEmployee(User $employee, CarbonInterface $from, CarbonInterface $through): Collection
    {
        return $this->forEmployees([$employee], $from, $through)->get($employee->id, collect());
    }

    public function forEmployees(iterable $employees, CarbonInterface $from, CarbonInterface $through): Collection
    {
        $users = collect($employees)
            ->filter(fn ($employee) => $employee instanceof User)
            ->keyBy('id')
            ->values();

        if ($users->isEmpty()) {
            return collect();
        }

        [$start, $end] = $this->normalizeRange($from, $through);
        $userIds = $users->pluck('id')->all();

        $versions = EmployeeWorkScheduleVersion::query()
            ->whereIn('user_id', $userIds)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $dayOffDays = DayOffRequestDay::query()
            ->with(['request', 'reviewer'])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DayOffRequestDay $day) => $this->sourceKey($day->user_id, $day->date->toDateString()));

        $vacationDays = VacationRequestDay::query()
            ->with(['request', 'reviewer'])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (VacationRequestDay $day) => $this->sourceKey($day->user_id, $day->date->toDateString()));

        $adjustments = EmployeeCalendarAdjustment::query()
            ->whereIn('user_id', $userIds)
            ->where(function ($query) use ($start, $end) {
                $query
                    ->whereDate('starts_on', '>=', $start->toDateString())
                    ->whereDate('starts_on', '<=', $end->toDateString())
                    ->orWhere(function ($range) use ($start) {
                        $range
                            ->whereNotNull('ends_on')
                            ->whereDate('starts_on', '<', $start->toDateString())
                            ->whereDate('ends_on', '>=', $start->toDateString());
                    })
                    ->orWhere(function ($replacement) use ($start, $end) {
                        $replacement
                            ->whereDate('replacement_date', '>=', $start->toDateString())
                            ->whereDate('replacement_date', '<=', $end->toDateString());
                    });
            })
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        return $users->mapWithKeys(function (User $user) use ($start, $end, $versions, $dayOffDays, $vacationDays, $adjustments) {
            $userVersions = $versions->get($user->id, collect());
            $userAdjustments = $adjustments->get($user->id, collect());
            $days = collect();

            for ($date = $start->copy(); $date->lte($end); $date = $date->addDay()) {
                $dateKey = $date->toDateString();
                $sourceKey = $this->sourceKey($user->id, $dateKey);
                $applicableAdjustments = $userAdjustments
                    ->filter(fn (EmployeeCalendarAdjustment $adjustment) => $this->adjustmentAppliesOn($adjustment, $dateKey))
                    ->values();

                $supersededAdjustmentIds = $applicableAdjustments
                    ->pluck('reverses_adjustment_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $activeAdjustments = $applicableAdjustments
                    ->reject(fn (EmployeeCalendarAdjustment $adjustment) => $adjustment->reverses_adjustment_id
                        || in_array((int) $adjustment->id, $supersededAdjustmentIds, true))
                    ->values();

                $supersededRequestDays = $activeAdjustments
                    ->filter(fn (EmployeeCalendarAdjustment $adjustment) => filled($adjustment->supersedes_request_type)
                        && filled($adjustment->supersedes_request_day_id))
                    ->map(fn (EmployeeCalendarAdjustment $adjustment) => $adjustment->supersedes_request_type.':'.$adjustment->supersedes_request_day_id)
                    ->all();

                $sources = collect();

                foreach ($dayOffDays->get($sourceKey, collect()) as $requestDay) {
                    $sources->push($this->requestSource(
                        type: 'day_off',
                        category: 'day_off',
                        requestDay: $requestDay,
                        request: $requestDay->request,
                        superseded: in_array('day_off_request_day:'.$requestDay->id, $supersededRequestDays, true),
                    ));
                }

                foreach ($vacationDays->get($sourceKey, collect()) as $requestDay) {
                    $sources->push($this->requestSource(
                        type: 'vacation',
                        category: 'vacation',
                        requestDay: $requestDay,
                        request: $requestDay->request,
                        superseded: in_array('vacation_request_day:'.$requestDay->id, $supersededRequestDays, true),
                    ));
                }

                foreach ($applicableAdjustments as $adjustment) {
                    $sources->push([
                        'type' => 'manual_adjustment',
                        'id' => $adjustment->id,
                        'operation' => $adjustment->operation,
                        'category' => $adjustment->category,
                        'reason' => $adjustment->reason,
                        'actor_id' => $adjustment->actor_id,
                        'actor_name' => $adjustment->actor_name_snapshot,
                        'starts_on' => $adjustment->starts_on?->toDateString(),
                        'ends_on' => $adjustment->ends_on?->toDateString(),
                        'replacement_date' => $adjustment->replacement_date?->toDateString(),
                        'supersedes_request_type' => $adjustment->supersedes_request_type,
                        'supersedes_request_day_id' => $adjustment->supersedes_request_day_id,
                        'superseded' => in_array((int) $adjustment->id, $supersededAdjustmentIds, true),
                        'is_reversal' => filled($adjustment->reverses_adjustment_id),
                    ]);
                }

                $hasPendingRequest = $sources->contains(fn (array $source) => in_array($source['type'], ['day_off', 'vacation'], true)
                    && $source['status'] === 'pending'
                    && ! $source['superseded']);

                $approvedRequestExists = $sources->contains(fn (array $source) => in_array($source['type'], ['day_off', 'vacation'], true)
                    && $source['status'] === 'approved'
                    && ! $source['superseded']);

                $base = $this->baseState($userVersions, $dateKey, (int) $date->dayOfWeekIso);
                $adjustedState = $this->adjustedState($activeAdjustments, $dateKey);
                $state = $adjustedState ?? match (true) {
                    $base['state'] === 'not_employed' => 'not_employed',
                    $approvedRequestExists => 'approved_absence',
                    default => $base['state'],
                };

                $days->push([
                    'date' => $dateKey,
                    'state' => $state,
                    'state_reason' => $base['reason'],
                    'pending' => $hasPendingRequest,
                    'schedule_known' => $base['schedule_known'],
                    'employment_start_date' => $base['employment_start_date'],
                    'sources' => $sources->values()->all(),
                ]);
            }

            return [$user->id => $days->keyBy('date')];
        });
    }

    private function normalizeRange(CarbonInterface $from, CarbonInterface $through): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($through)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Calendar range end must be on or after its start.');
        }

        if ($start->diffInDays($end) > 365) {
            throw new InvalidArgumentException('Calendar range cannot exceed one year.');
        }

        return [$start, $end];
    }

    private function sourceKey(int $userId, mixed $date): string
    {
        return $userId.':'.CarbonImmutable::parse($date)->toDateString();
    }

    private function requestSource(string $type, string $category, Model $requestDay, mixed $request, bool $superseded): array
    {
        return [
            'type' => $type,
            'category' => $category,
            'request_id' => $request?->id,
            'request_day_id' => $requestDay->id,
            'status' => $requestDay->status,
            'reason' => $request?->reason,
            'admin_comment' => $requestDay->admin_comment,
            'reviewed_at' => $requestDay->reviewed_at?->toIso8601String(),
            'reviewer_name' => $requestDay->reviewer?->name,
            'superseded' => $superseded,
        ];
    }

    private function baseState(Collection $versions, string $date, int $isoDay): array
    {
        $version = $versions->last(fn (EmployeeWorkScheduleVersion $version) => $version->effective_from->toDateString() <= $date);
        $earliestVersion = $versions->first();

        if (! $version && $earliestVersion?->employment_start_date
            && $date < $earliestVersion->employment_start_date->toDateString()) {
            return [
                'state' => 'not_employed',
                'reason' => 'before_employment_start',
                'schedule_known' => true,
                'employment_start_date' => $earliestVersion->employment_start_date->toDateString(),
            ];
        }

        if (! $version) {
            return [
                'state' => 'unknown_schedule',
                'reason' => 'schedule_not_recorded',
                'schedule_known' => false,
                'employment_start_date' => $earliestVersion?->employment_start_date?->toDateString(),
            ];
        }

        $employmentStart = $version->employment_start_date?->toDateString();

        if (! $employmentStart || ! is_array($version->weekend_days)) {
            return [
                'state' => 'unknown_schedule',
                'reason' => 'schedule_incomplete',
                'schedule_known' => false,
                'employment_start_date' => $employmentStart,
            ];
        }

        if ($date < $employmentStart) {
            return [
                'state' => 'not_employed',
                'reason' => 'before_employment_start',
                'schedule_known' => true,
                'employment_start_date' => $employmentStart,
            ];
        }

        $weekendDays = array_map('intval', $version->weekend_days);

        return [
            'state' => in_array($isoDay, $weekendDays, true) ? 'regular_day_off' : 'planned_work',
            'reason' => null,
            'schedule_known' => true,
            'employment_start_date' => $employmentStart,
        ];
    }

    private function adjustmentAppliesOn(EmployeeCalendarAdjustment $adjustment, string $date): bool
    {
        if ($adjustment->replacement_date?->toDateString() === $date) {
            return true;
        }

        $start = $adjustment->starts_on?->toDateString();
        $end = $adjustment->ends_on?->toDateString() ?? $start;

        return $start !== null && $date >= $start && $date <= $end;
    }

    private function adjustedState(Collection $adjustments, string $date): ?string
    {
        foreach ($adjustments->reverse() as $adjustment) {
            $newState = $adjustment->new_state ?? [];

            if ($adjustment->replacement_date?->toDateString() === $date) {
                $state = $newState['replacement_state'] ?? null;

                if (in_array($state, ['planned_work', 'regular_day_off', 'approved_absence', 'unknown_schedule', 'not_employed'], true)) {
                    return $state;
                }
            }

            $state = $newState['state'] ?? null;

            if (in_array($state, ['planned_work', 'regular_day_off', 'approved_absence', 'unknown_schedule', 'not_employed'], true)) {
                return $state;
            }
        }

        return null;
    }
}

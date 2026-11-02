<?php

namespace App\Services\Calendar;

use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequestDay;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarSummaryService
{
    public function build(Carbon|string|null $date = null): array
    {
        Carbon::setLocale('ru');

        $day = $date
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();

        $workers = $this->getWorkersForDay($day);

        return [
            'date' => $day,
            'shift' => $this->makeShiftSummary(
                total: $workers['total'],
                notWorking: $workers['not_working_count'],
            ),
            'workers' => $workers,
        ];
    }

    protected function getWorkersForDay(Carbon $day): array
    {
        $staff = User::query()
            ->activeStaff()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')
            ->get();

        $users = $staff->where('role', 'cleaner')->values();
        $userIds = $staff->pluck('id');

        $dayOffDays = DayOffRequestDay::query()
            ->with(['request'])
            ->whereDate('date', $day->toDateString())
            ->where('status', 'approved')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $vacationDays = VacationRequestDay::query()
            ->with(['request'])
            ->whereDate('date', $day->toDateString())
            ->where('status', 'approved')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $notWorking = $users->filter(function (User $user) use ($day, $dayOffDays, $vacationDays) {
            return $this->isRegularWeekend($user, $day)
                || $dayOffDays->has($user->id)
                || $vacationDays->has($user->id);
        })->map(function (User $user) use ($dayOffDays, $vacationDays) {
            if ($vacationDays->has($user->id)) {
                $vacationDay = $vacationDays->get($user->id);
                $request = $vacationDay?->request;

                $user->not_working_reason = filled($request?->reason)
                    ? 'Отпуск: '.$request->reason
                    : 'Отпуск';

                return $user;
            }

            if ($dayOffDays->has($user->id)) {
                $dayOff = $dayOffDays->get($user->id);

                $user->not_working_reason = filled($dayOff?->request?->reason)
                    ? 'Выходной: '.$dayOff->request->reason
                    : 'Выходной';

                return $user;
            }

            $user->not_working_reason = 'Регулярный выходной';

            return $user;
        })->values();

        $working = $users
            ->whereNotIn('id', $notWorking->pluck('id'))
            ->values();

        $byRole = collect(['cleaner', 'supervisor'])
            ->mapWithKeys(function (string $role) use ($staff, $day, $dayOffDays, $vacationDays) {
                $roleUsers = $staff->where('role', $role)->values();

                return [$role => $this->makeRoleSummary($roleUsers, $day, $dayOffDays, $vacationDays)];
            })
            ->all();

        return [
            'total' => $users->count(),
            'working_count' => $working->count(),
            'not_working_count' => $notWorking->count(),
            'working' => $working,
            'not_working' => $notWorking,
            'by_role' => $byRole,
        ];
    }

    protected function makeRoleSummary(Collection $users, Carbon $day, Collection $dayOffDays, Collection $vacationDays): array
    {
        $absentUsers = $users
            ->filter(fn (User $user) => $vacationDays->has($user->id)
                || $dayOffDays->has($user->id)
                || $this->isRegularWeekend($user, $day))
            ->values();

        $absent = $absentUsers
            ->map(function (User $user) use ($dayOffDays, $vacationDays) {
                $reason = match (true) {
                    $vacationDays->has($user->id) => 'vacation',
                    $dayOffDays->has($user->id) => 'day_off',
                    default => 'regular_weekend',
                };

                return ['name' => $user->name, 'reason' => $reason];
            })
            ->values();

        $absentIds = $absentUsers->pluck('id');
        $available = $users
            ->reject(fn (User $user) => $absentIds->contains($user->id))
            ->map(fn (User $user) => ['name' => $user->name])
            ->values();

        return [
            'total' => $users->count(),
            'available_count' => $available->count(),
            'absent_count' => $absent->count(),
            'available' => $available->all(),
            'absent' => $absent->all(),
        ];
    }

    protected function isRegularWeekend(User $user, Carbon $day): bool
    {
        return $this->normalizeWeekendDays($user->weekend_days ?? [])
            ->contains($day->dayOfWeekIso);
    }

    protected function normalizeWeekendDays(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($day) => (int) $day)->filter()->values();
        }

        if (is_array($value)) {
            return collect($value)->map(fn ($day) => (int) $day)->filter()->values();
        }

        if (is_string($value) && filled($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return collect($decoded)->map(fn ($day) => (int) $day)->filter()->values();
            }

            return collect(explode(',', $value))
                ->map(fn ($day) => (int) trim($day))
                ->filter()
                ->values();
        }

        return collect();
    }

    protected function makeShiftSummary(int $total, int $notWorking): array
    {
        $working = max($total - $notWorking, 0);

        if ($total < 1) {
            return [
                'total' => 0,
                'working' => 0,
                'not_working' => 0,
                'working_percent' => null,
                'level' => 'unknown',
                'label' => 'Нет данных о составе',
            ];
        }

        $percent = (int) round(($working / $total) * 100);

        $level = match (true) {
            $percent < 60 => 'critical',
            $percent < 80 => 'warning',
            default => 'good',
        };

        $label = match ($level) {
            'critical' => 'Критическая смена',
            'warning' => 'Средняя нагрузка',
            default => 'Нормальная смена',
        };

        return [
            'total' => $total,
            'working' => $working,
            'not_working' => $notWorking,
            'working_percent' => $percent,
            'level' => $level,
            'label' => $label,
        ];
    }
}

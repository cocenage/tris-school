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
        $users = User::query()
            ->activeStaff()
            ->where('role', 'cleaner')
            ->orderBy('name')
            ->get();

        $userIds = $users->pluck('id');

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
        })->map(function (User $user) use ($day, $dayOffDays, $vacationDays) {
            if ($vacationDays->has($user->id)) {
                $vacationDay = $vacationDays->get($user->id);
                $request = $vacationDay?->request;

                $user->not_working_reason = filled($request?->reason)
                    ? 'Отпуск: ' . $request->reason
                    : 'Отпуск';

                return $user;
            }

            if ($dayOffDays->has($user->id)) {
                $dayOff = $dayOffDays->get($user->id);

                $user->not_working_reason = filled($dayOff?->request?->reason)
                    ? 'Выходной: ' . $dayOff->request->reason
                    : 'Выходной';

                return $user;
            }

            $user->not_working_reason = 'Регулярный выходной';

            return $user;
        })->values();

        $working = $users
            ->whereNotIn('id', $notWorking->pluck('id'))
            ->values();

        return [
            'total' => $users->count(),
            'working_count' => $working->count(),
            'not_working_count' => $notWorking->count(),
            'working' => $working,
            'not_working' => $notWorking,
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

        $percent = $total > 0
            ? (int) round(($working / $total) * 100)
            : 0;

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
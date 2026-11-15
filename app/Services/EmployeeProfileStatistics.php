<?php

namespace App\Services;

use App\Models\TrisMareSnapshot;
use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarService;
use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use App\Services\Learning\LearningCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;

class EmployeeProfileStatistics
{
    public function __construct(
        private readonly EmployeeWorkCalendarService $calendar,
        private readonly EmployeeWorkCalendarStatisticsService $calendarStatistics,
        private readonly ProfileActivityTimeline $timeline,
        private readonly LearningCatalog $learning,
    ) {}

    public static function canView(?User $user): bool
    {
        return $user?->is_active
            && $user->isApproved()
            && in_array($user->role, ['cleaner', 'supervisor'], true)
            && ! app(RolePreviewContext::class)->isActive();
    }

    public static function today(): CarbonImmutable
    {
        return Date::now(config('app.timezone', 'Europe/Rome'))->toImmutable()->startOfDay();
    }

    public function forMonth(User $employee, CarbonImmutable $month): array
    {
        $tables = Schema::getTableListing(schemaQualified: false);
        $hasTables = fn (array $required): bool => array_diff($required, $tables) === [];
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        $today = self::today();
        $days = collect();
        $nextWorkday = null;
        $calendarAvailable = $hasTables([
            'employee_work_schedule_versions', 'employee_calendar_adjustments',
            'day_off_requests', 'day_off_request_days', 'vacation_requests', 'vacation_request_days',
        ]);

        if ($calendarAvailable) {
            $nextThrough = $today->addDays(30);
            $coversUpcoming = $start->diffInDays($nextThrough, absolute: true) <= 62;
            $calendar = $this->calendar->forEmployee(
                $employee,
                $coversUpcoming ? $start->min($today) : $start,
                $coversUpcoming ? $end->max($nextThrough) : $end,
            );
            $days = $calendar->filter(fn (array $day): bool => $day['date'] >= $start->toDateString() && $day['date'] <= $end->toDateString());
            $upcoming = $coversUpcoming ? $calendar : $this->calendar->forEmployee($employee, $today, $nextThrough);
            $nextWorkday = $upcoming->first(fn (array $day): bool => $day['date'] >= $today->toDateString()
                && $day['date'] <= $nextThrough->toDateString() && $day['state'] === 'planned_work')['date'] ?? null;
        }

        $qualityAvailable = $hasTables(['control_responses', 'coachings', 'controls', 'apartments']);
        $activity = $qualityAvailable ? $this->timeline->forCleanerBetween($employee->id, $start->toDateString(), $end->toDateString()) : collect();
        $controls = $activity->where('item_type', 'control');
        $scores = $controls->filter(fn ($control): bool => $control->max_points > 0)->map(fn ($control): int => $control->score_percent
            ?? (int) round(100 * $control->total_points / $control->max_points));

        $progress = $hasTables(['instructions', 'instruction_categories', 'learning_lesson_progress'])
            ? $this->learning->progress($employee, $this->learning->lessons($employee)->get())
            : null;

        return [
            'days' => $days,
            'schedule' => $calendarAvailable ? $this->calendarStatistics->summarizeDays($days) : null,
            'next_workday' => $nextWorkday,
            'activity' => $activity->groupBy(fn ($entry): string => $entry->sort_date->toDateString()),
            'quality' => $qualityAvailable ? [
                'controls' => $controls->count(),
                'coachings' => $activity->count() - $controls->count(),
                'zones' => $controls->countBy(fn ($control): string => (string) $control->result_zone),
                'average' => $scores->isEmpty() ? null : (int) round($scores->avg()),
                'penalties' => $controls->sum('penalty_points'),
                'errors' => $controls->sum('errors_count'),
            ] : null,
            'applications' => UserApplicationBadgeService::statusCounts($employee->id, $tables),
            'learning' => $progress,
            'mare' => $hasTables(['tris_mare_snapshots'])
                ? TrisMareSnapshot::query()->where('user_id', $employee->id)->latest('synced_at')->latest('id')->first()
                : null,
        ];
    }
}

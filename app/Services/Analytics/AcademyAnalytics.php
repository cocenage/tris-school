<?php

namespace App\Services\Analytics;

use App\Models\ControlResponse;
use App\Models\TelegramChat;
use App\Models\TelegramOperationalEventEvidence;
use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarService;
use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AcademyAnalytics
{
    public function __construct(
        private readonly EmployeeWorkCalendarService $calendar,
        private readonly EmployeeWorkCalendarStatisticsService $calendarStatistics,
        private readonly TelegramDistrictRouteRegistry $districts,
    ) {}

    public function quality(User $viewer, CarbonImmutable $from, CarbonImmutable $through): Collection
    {
        // The same date precedence as the existing control history.
        $date = "COALESCE(NULLIF(inspection_date, ''), NULLIF(cleaning_date, ''), NULLIF(sent_at, ''), created_at)";

        return ControlResponse::query()
            ->select(['id', 'cleaner_id', 'supervisor_id', 'apartment_id', 'result_zone', 'score_percent', 'total_points', 'max_points'])
            ->selectRaw($date.' AS report_date')
            ->where('status', ControlResponse::STATUS_SENT)
            ->when(! $viewer->isAdmin(), fn ($query) => $query->where('supervisor_id', $viewer->id))
            ->whereRaw($date.' >= ?', [$from->toDateString()])
            ->whereRaw($date.' < ?', [$through->addDay()->toDateString()])
            ->with(['cleaner:id,name', 'apartment:id,name'])
            ->orderBy('report_date')->orderBy('id')->get();
    }

    public function qualitySummary(Collection $controls): array
    {
        $scored = $controls->filter(fn (ControlResponse $control): bool => $control->max_points > 0);
        $average = $scored->avg(fn (ControlResponse $control) => $control->score_percent
            ?? 100 * $control->total_points / $control->max_points);
        $zones = $controls->countBy(fn (ControlResponse $control): string => match ($control->result_zone) {
            'green', 'yellow', 'red' => $control->result_zone,
            default => 'unknown',
        });

        return [
            'count' => $controls->count(),
            'average' => $average === null ? null : (int) round($average),
            'scored' => $scored->count(),
            'zones' => $zones->all(),
        ];
    }

    public function schedule(CarbonImmutable $from, CarbonImmutable $through): array
    {
        $staff = User::query()->whereIn('role', ['cleaner', 'supervisor'])
            ->where('status', 'approved')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'role', 'work_started_at']);
        $calendar = $this->calendar->forEmployees($staff, $from, $through);
        $daily = $calendar->flatten(1)->groupBy('date')
            ->map(fn (Collection $days): array => $this->calendarStatistics->summarizeDays($days));
        $unassignedWorkByDate = $staff->where('role', 'cleaner')
            ->flatMap(fn (User $employee) => $calendar->get($employee->id, collect()))
            ->filter(fn (array $day): bool => $day['state'] === 'no_work')
            ->countBy('date');
        $daily = $daily->map(fn (array $counts, string $date): array => [
            ...$counts,
            'unassigned_work_days' => $unassignedWorkByDate->get($date, 0),
        ]);

        return [
            'daily' => $daily,
            'staff' => $staff->map(fn (User $employee): array => [
                'employee' => $employee,
                ...$this->calendarStatistics->summarizeDays($calendar->get($employee->id, collect())),
            ]),
        ];
    }

    public function districtActivity(CarbonImmutable $from, CarbonImmutable $through): Collection
    {
        $routes = $this->districts->sourceRoutes();
        if ($routes->isEmpty()) {
            return collect();
        }

        try {
            $schema = Schema::connection('analytics');
            if (! $schema->hasTable('telegram_operational_event_evidence')) {
                return collect();
            }

            $chatIds = TelegramChat::query()->whereIn('telegram_chat_id', $routes->pluck('chat_id'))
                ->pluck('telegram_chat_id')->map(fn ($id): string => (string) $id);
            $evidence = TelegramOperationalEventEvidence::query()
                ->where('is_current_revision', true)
                ->where('occurred_at', '>=', $from->utc())
                ->where('occurred_at', '<', $through->addDay()->utc())
                ->where(fn ($query) => $query
                    ->where(fn ($opened) => $opened->where('transition', 'created')->where('status_after', 'open'))
                    ->orWhere(fn ($reopened) => $reopened->where('transition', 'reopened')->where('status_before', 'resolved')->where('status_after', 'reopened'))
                    ->orWhere(fn ($closed) => $closed->where('transition', 'resolved')->where('status_after', 'resolved')
                        ->where(fn ($before) => $before->whereNull('status_before')->orWhere('status_before', '!=', 'resolved'))))
                ->whereHas('event', fn ($query) => $query->whereNotIn('primary_type', ['positive_contribution', 'resolution']))
                ->whereHas('event.chat', fn ($query) => $query->whereIn('telegram_chat_id', $chatIds))
                ->with('event.chat:id,telegram_chat_id')
                ->get()
                ->groupBy(fn (TelegramOperationalEventEvidence $entry) => (string) $entry->event->chat->telegram_chat_id);
        } catch (QueryException $exception) {
            report($exception);

            return collect();
        }

        return $routes->map(function (array $route) use ($evidence, $chatIds): array {
            $entries = $evidence->get($route['chat_id'], collect());
            $closed = $entries->where('transition', 'resolved');
            $available = $chatIds->containsStrict($route['chat_id']);

            return [
                'label' => $route['label'],
                'opened' => $available ? $entries->count() - $closed->count() : null,
                'closed' => $available ? $closed->count() : null,
            ];
        });
    }
}

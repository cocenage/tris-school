<?php

namespace App\Services\Antigoccia;

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use App\Models\Apartment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AntigocciaOverview
{
    /**
     * Only apartments with an application or at least one ANTIGOCCIA report
     * appear in the dashboard. Never show an unrelated apartment directory.
     */
    public function apartmentQuery(string $search = ''): Builder
    {
        $search = mb_strtolower(trim($search));

        return Apartment::query()
            ->where(function (Builder $query): void {
                $query->whereIn('id', AntigocciaApplication::query()->select('apartment_id'))
                    ->orWhereIn('id', AntigocciaReport::query()->select('apartment_id'));
            })
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw("LOWER(COALESCE(address, '')) LIKE ?", ['%'.$search.'%']);
            }))
            ->orderBy('name');
    }

    public function totals(): array
    {
        return [
            'treated_apartments' => AntigocciaApplication::query()->distinct()->count('apartment_id'),
            'reports' => AntigocciaReport::query()->count(),
            'with_contamination' => AntigocciaReport::query()->where('remaining', true)->count(),
            'last_30_days' => AntigocciaReport::query()
                ->whereDate('cleaning_date', '>=', CarbonImmutable::now('Europe/Rome')->subDays(29)->toDateString())
                ->whereDate('cleaning_date', '<=', CarbonImmutable::now('Europe/Rome')->toDateString())
                ->count(),
        ];
    }

    /**
     * Bulk-load the treatment date and aggregates for the current page.
     * Never execute four queries per apartment in the table.
     *
     * @param Collection<int, Apartment> $apartments
     * @return array<int, array<string,mixed>>
     */
    public function rowsForApartments(Collection $apartments): array
    {
        $ids = $apartments->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $applications = AntigocciaApplication::query()
            ->whereIn('apartment_id', $ids)
            ->orderByDesc('applied_on')->orderByDesc('id')
            ->get(['id', 'apartment_id', 'applied_on'])
            ->groupBy('apartment_id');

        $reports = AntigocciaReport::query()
            ->whereIn('apartment_id', $ids)
            ->select('apartment_id')
            ->selectRaw('COUNT(*) as reports_count')
            ->selectRaw('SUM(CASE WHEN remaining THEN 1 ELSE 0 END) as issues_count')
            ->selectRaw('MAX(cleaning_date) as last_control_date')
            ->groupBy('apartment_id')
            ->get()->keyBy('apartment_id');

        $today = CarbonImmutable::now('Europe/Rome')->startOfDay();

        return $apartments->map(function (Apartment $apartment) use ($applications, $reports, $today): array {
            $date = $applications->get($apartment->id)?->first()?->applied_on;
            $summary = $reports->get($apartment->id);
            return [
                'id' => $apartment->id,
                'name' => $apartment->name,
                'address' => $apartment->address,
                'applied_on' => $date?->format('d.m.Y'),
                'days_since_application' => $date === null ? null : (int) max(0,
                    CarbonImmutable::parse($date, 'Europe/Rome')->startOfDay()->diffInDays($today)),
                'reports' => (int) ($summary?->reports_count ?? 0),
                'issues' => (int) ($summary?->issues_count ?? 0),
                'last_control_date' => $summary?->last_control_date
                    ? CarbonImmutable::parse($summary->last_control_date)->format('d.m.Y')
                    : null,
            ];
        })->all();
    }
    /**
     * Activity counts actual submissions, not the number of cleaning visits.
     * Coverage is calculated against the current recorded treatment cycle.
     */
    public function activity(int $days = 7): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 7;
        $today = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $start = $today->subDays($days - 1);
        $daily = [];
        for ($day = $start; $day <= $today; $day = $day->addDay()) {
            $daily[$day->toDateString()] = 0;
        }

        $latest = AntigocciaApplication::query()
            ->whereDate('applied_on', '<=', $today->toDateString())
            ->select('apartment_id')->selectRaw('MAX(applied_on) as applied_on')
            ->groupBy('apartment_id')->pluck('applied_on', 'apartment_id');

        $apartmentStats = [];
        $staff = [];
        $covered = [];
        $total = 0;
        $reports = AntigocciaReport::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $today->addDay())
            ->select(['id', 'apartment_id', 'user_id', 'cleaner_name', 'created_at', 'cleaning_date']);

        foreach ($reports->lazyById(500) as $report) {
            $total++;
            $submitted = $report->created_at->toDateString();
            $daily[$submitted] = ($daily[$submitted] ?? 0) + 1;
            $date = $report->cleaning_date->toDateString();
            $id = $report->apartment_id;
            $apartmentStats[$id] ??= ['count' => 0, 'dates' => [], 'last' => null];
            $apartmentStats[$id]['count']++;
            $apartmentStats[$id]['dates'][$date] = true;
            $apartmentStats[$id]['last'] = max($apartmentStats[$id]['last'] ?? '', $date);
            if (isset($latest[$id]) && $date >= $latest[$id]) {
                $covered[$id] = true;
            }
            $key = $report->user_id === null ? 'deleted:'.$report->cleaner_name : 'user:'.$report->user_id;
            $staff[$key] ??= ['name' => $report->cleaner_name, 'count' => 0, 'last' => null];
            $staff[$key]['count']++;
            $staff[$key]['last'] = max($staff[$key]['last'] ?? '', $submitted);
        }

        $ids = array_unique(array_merge($latest->keys()->all(), array_keys($apartmentStats)));
        $rows = Apartment::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(function (Apartment $apartment) use ($apartmentStats, $latest, $covered): array {
                $stats = $apartmentStats[$apartment->id] ?? ['count' => 0, 'dates' => [], 'last' => null];
                $dates = array_keys($stats['dates']);
                sort($dates);
                $interval = count($dates) > 1
                    ? round(CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse(end($dates))) / (count($dates) - 1), 1)
                    : null;
                return [
                    'name' => $apartment->name,
                    'applied_on' => $latest[$apartment->id] ?? null,
                    'count' => $stats['count'],
                    'last' => $stats['last'],
                    'mean_interval' => $interval,
                    'covered' => isset($covered[$apartment->id]),
                ];
            })->all();

        usort($staff, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'days' => $days, 'reports' => $total, 'daily' => $daily,
            'treated' => $latest->count(), 'covered' => count($covered),
            'uncovered' => $latest->count() - count($covered),
            'staff_count' => count($staff), 'staff' => $staff, 'apartments' => $rows,
        ];
    }

}

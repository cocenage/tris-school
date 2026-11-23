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
}

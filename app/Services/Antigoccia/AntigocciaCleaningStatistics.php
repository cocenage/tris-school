<?php

namespace App\Services\Antigoccia;

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use App\Models\Apartment;
use App\Models\ControlResponse;
use Carbon\CarbonImmutable;

class AntigocciaCleaningStatistics
{
    /**
     * Count distinct dates supported by cleaning inspection records or ANTIGOCCIA
     * reports. This is a LOWER BOUND, not a guaranteed total of all cleanings.
     * Multiple cleanings on one day cannot be distinguished from these sources.
     */
    public function forApartment(Apartment $apartment, ?AntigocciaApplication $application): array
    {
        $latest = AntigocciaReport::query()
            ->where('apartment_id', $apartment->id)
            ->orderByDesc('cleaning_date')->orderByDesc('id')
            ->first();

        $start = $latest?->cleaning_date ?? $application?->applied_on;
        if ($start === null) {
            return ['recorded_cleaning_days' => null, 'last_control_date' => null];
        }

        $since = $start->toDateString();
        $today = CarbonImmutable::now('Europe/Rome')->toDateString();

        $controlDates = ControlResponse::query()
            ->where('apartment_id', $apartment->id)
            ->where('status', ControlResponse::STATUS_SENT)
            ->whereNotNull('cleaning_date')
            ->whereDate('cleaning_date', '>', $since)
            ->whereDate('cleaning_date', '<=', $today)
            ->distinct()->pluck('cleaning_date');

        $reportDates = AntigocciaReport::query()
            ->where('apartment_id', $apartment->id)
            ->whereDate('cleaning_date', '>', $since)
            ->whereDate('cleaning_date', '<=', $today)
            ->distinct()->pluck('cleaning_date');

        $count = $controlDates->merge($reportDates)
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()->count();

        return [
            'recorded_cleaning_days' => $count,
            'last_control_date' => $latest?->cleaning_date?->toDateString(),
        ];
    }
}

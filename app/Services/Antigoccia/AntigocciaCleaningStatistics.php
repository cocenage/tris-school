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
     * Gap to the previous distinct recorded cleaning date. Inspections
     * and Antigoccia reports are evidence of cleaning, not a complete
     * cleaning schedule. No earlier record means an unknown interval.
     */
    public function daysSincePreviousRecordedCleaning(int $apartmentId, string $cleaningDate): ?int
    {
        $controlDate = ControlResponse::query()
            ->where('apartment_id', $apartmentId)
            ->where('status', ControlResponse::STATUS_SENT)
            ->whereDate('cleaning_date', '<', $cleaningDate)
            ->max('cleaning_date');

        $reportDate = AntigocciaReport::query()
            ->where('apartment_id', $apartmentId)
            ->whereDate('cleaning_date', '<', $cleaningDate)
            ->max('cleaning_date');

        $previous = max($controlDate ?? '', $reportDate ?? '');
        if ($previous === '') {
            return null;
        }

        return (int) CarbonImmutable::parse($previous, 'Europe/Rome')->startOfDay()
            ->diffInDays(CarbonImmutable::parse($cleaningDate, 'Europe/Rome')->startOfDay());
    }

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

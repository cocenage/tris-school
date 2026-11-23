<?php

namespace App\Services\Antigoccia;

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use App\Models\Apartment;
use Carbon\CarbonImmutable;

class AntigocciaAnalytics
{
    public function forApartment(Apartment $apartment): array
    {
        $application = AntigocciaApplication::query()
            ->where('apartment_id', $apartment->id)
            ->orderByDesc('applied_on')->orderByDesc('id')->first();

        $reports = AntigocciaReport::query()
            ->where('apartment_id', $apartment->id)
            ->when($application, fn ($query) => $query->whereDate('cleaning_date', '>=', $application->applied_on->toDateString()))
            ->orderBy('cleaning_date')->orderBy('id')
            ->get(['id', 'cleaning_date', 'answers']);

        $surfaces = [];
        foreach (AntigocciaAssessment::SURFACES as $key => $label) {
            $evaluated = $reports->filter(fn ($report) => data_get($report->answers, "$key.dirt") !== null)->values();
            $difficult = $evaluated->filter(fn ($report) => in_array((string) data_get($report->answers, "$key.removal"), ['1', '2'], true));
            $failed = $evaluated->filter(fn ($report) => (string) data_get($report->answers, "$key.removal") === '2');
            $firstIssue = $difficult->first();
            $firstIssuePosition = $firstIssue === null ? null : $evaluated->search(fn ($report) => $report->id === $firstIssue->id) + 1;
            $surfaces[$key] = [
                'label' => $label,
                'total' => $evaluated->count(),
                'difficult' => $difficult->count(),
                'failed' => $failed->count(),
                'remaining_count' => $evaluated->filter(fn ($report) => data_get($report->answers, "$key.remaining") === 'yes')->count(),
                'first_issue_date' => $firstIssue?->cleaning_date?->format('d.m.Y'),
                'first_issue_report_number' => $firstIssuePosition,
                'recent' => $evaluated->take(-12)->map(fn ($report) => [
                    'date' => $report->cleaning_date->format('d.m.Y'),
                    'score' => data_get($report->answers, "$key.removal"),
                ])->values()->all(),
            ];
        }

        return [
            'application' => $application,
            'days_since_application' => $application
                ? (int) max(0, CarbonImmutable::parse($application->applied_on, 'Europe/Rome')
                    ->startOfDay()->diffInDays(CarbonImmutable::now('Europe/Rome')->startOfDay()))
                : null,
            ...app(AntigocciaCleaningStatistics::class)->forApartment($apartment, $application),
            'report_count' => $reports->count(),
            'surfaces' => $surfaces,
        ];
    }
}

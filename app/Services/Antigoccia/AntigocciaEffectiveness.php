<?php

namespace App\Services\Antigoccia;

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use Carbon\CarbonImmutable;

class AntigocciaEffectiveness
{
    public const PERIODS = [30, 90, 365, 0];

    /**
     * Descriptive, self-reported post-application data. These rates must not
     * be presented as a causal proof that the treatment works.
     *
     * Each report is attributed to the most recent application on or before
     * its cleaning date. Missing/pre-application reports are excluded.
     */
    public function summarize(int $periodDays = 90, ?int $apartmentId = null): array
    {
        if (! in_array($periodDays, self::PERIODS, true)) {
            $periodDays = 90;
        }

        $today = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $startDate = $periodDays > 0 ? $today->subDays($periodDays - 1)->toDateString() : null;

        $applications = AntigocciaApplication::query()
            ->when($apartmentId !== null, fn ($query) => $query->where('apartment_id', $apartmentId))
            ->orderBy('applied_on')
            ->orderBy('id')
            ->get(['id', 'apartment_id', 'applied_on'])
            ->groupBy('apartment_id');

        $age = [
            'first_week' => $this->blank('0–7 дней'),
            'first_month' => $this->blank('8–30 дней'),
            'second_month' => $this->blank('31–60 дней'),
            'later' => $this->blank('Более 60 дней'),
        ];
        $gaps = [
            'short' => $this->blank('0–2 дня'),
            'medium' => $this->blank('3–7 дней'),
            'long' => $this->blank('8+ дней'),
            'unknown' => $this->blank('Интервал неизвестен'),
        ];
        $surfaces = [];
        foreach (AntigocciaAssessment::SURFACES as $key => $label) {
            $surfaces[$key] = $this->blank($label);
        }

        $overall = $this->blank('Все поверхности');
        $apartments = [];
        $unlinked = 0;

        $reports = AntigocciaReport::query()
            ->when($apartmentId !== null, fn ($query) => $query->where('apartment_id', $apartmentId))
            ->when($startDate !== null, fn ($query) => $query->whereDate('cleaning_date', '>=', $startDate))
            ->whereDate('cleaning_date', '<=', $today->toDateString())
            ->select(['id', 'apartment_id', 'cleaning_date', 'days_since_previous_cleaning', 'answers']);

        foreach ($reports->lazyById(300) as $report) {
            if ($report->cleaning_date === null) {
                continue;
            }

            $date = CarbonImmutable::parse($report->cleaning_date->toDateString(), 'Europe/Rome');
            $matchingApplication = null;

            foreach ($applications->get($report->apartment_id, collect()) as $application) {
                if ($application->applied_on->toDateString() <= $date->toDateString()) {
                    $matchingApplication = $application;
                } else {
                    break;
                }
            }

            if ($matchingApplication === null) {
                $unlinked++;
                continue;
            }

            $apartments[$report->apartment_id] = true;
            $ageDays = (int) CarbonImmutable::parse($matchingApplication->applied_on->toDateString(), 'Europe/Rome')
                ->diffInDays($date);
            $ageKey = match (true) {
                $ageDays <= 7 => 'first_week',
                $ageDays <= 30 => 'first_month',
                $ageDays <= 60 => 'second_month',
                default => 'later',
            };

            $daysSincePrevious = $report->days_since_previous_cleaning;
            $gapKey = match (true) {
                $daysSincePrevious === null => 'unknown',
                $daysSincePrevious <= 2 => 'short',
                $daysSincePrevious <= 7 => 'medium',
                default => 'long',
            };

            $overall['reports']++;
            $age[$ageKey]['reports']++;
            $gaps[$gapKey]['reports']++;

            foreach (AntigocciaAssessment::SURFACES as $surface => $label) {
                $answer = $report->answers[$surface] ?? null;
                if (! is_array($answer)) {
                    continue;
                }

                $this->addObservation($overall, $answer);
                $this->addObservation($age[$ageKey], $answer);
                $this->addObservation($gaps[$gapKey], $answer);
                $this->addObservation($surfaces[$surface], $answer);
                $surfaces[$surface]['reports']++;
            }
        }

        return [
            'period_days' => $periodDays,
            'apartment_count' => count($apartments),
            'unlinked_reports' => $unlinked,
            'overall' => $this->withRates($overall),
            'ages' => array_map($this->withRates(...), $age),
            'gaps' => array_map($this->withRates(...), $gaps),
            'surfaces' => array_map($this->withRates(...), $surfaces),
        ];
    }

    private function blank(string $label): array
    {
        return [
            'label' => $label,
            'reports' => 0,
            'residual_observations' => 0,
            'residual_yes' => 0,
            'dirty_observations' => 0,
            'evaluable_removal' => 0,
            'easy' => 0,
            'difficult' => 0,
            'without_visible_dirt' => 0,
            'heavy_dirt' => 0,
            'inconsistent' => 0,
        ];
    }

    private function addObservation(array &$metrics, array $answer): void
    {
        $remaining = $answer['remaining'] ?? null;
        if (in_array($remaining, ['yes', 'no'], true)) {
            $metrics['residual_observations']++;
            if ($remaining === 'yes') {
                $metrics['residual_yes']++;
            }
        }

        $dirt = $answer['dirt'] ?? null;
        if (! in_array($dirt, [0, 1, 2, '0', '1', '2'], true)) {
            return;
        }

        $dirt = (int) $dirt;
        if ($dirt === 0) {
            $metrics['without_visible_dirt']++;
            return;
        }

        $metrics['dirty_observations']++;
        if ($dirt === 2) {
            $metrics['heavy_dirt']++;
        }

        $removal = $answer['removal'] ?? null;
        if (in_array($removal, ['easy', 0, '0'], true)) {
            $metrics['evaluable_removal']++;
            $metrics['easy']++;
        } elseif (in_array($removal, ['medium', 'hard', 1, '1', 2, '2'], true)) {
            $metrics['evaluable_removal']++;
            $metrics['difficult']++;
        } elseif ($removal === 'no_dirt') {
            // Dirt reported as present, yet removal marked "no dirt".
            // Do not count this contradictory observation as easy.
            $metrics['inconsistent']++;
        }
    }

    private function withRates(array $metrics): array
    {
        $metrics['residual_rate'] = $this->rate($metrics['residual_yes'], $metrics['residual_observations']);
        $metrics['easy_rate'] = $this->rate($metrics['easy'], $metrics['evaluable_removal']);
        $metrics['difficult_rate'] = $this->rate($metrics['difficult'], $metrics['evaluable_removal']);

        return $metrics;
    }

    private function rate(int $numerator, int $denominator): ?int
    {
        return $denominator > 0 ? (int) round($numerator * 100 / $denominator) : null;
    }
}

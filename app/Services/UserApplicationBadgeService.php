<?php

namespace App\Services;

use App\Models\DayOffRequest;
use App\Models\FeedbackSuggestion;
use App\Models\InventoryRequest;
use App\Models\SalaryQuestion;
use App\Models\ScheduleQuestion;
use App\Models\VacationRequest;

class UserApplicationBadgeService
{
    private const APPLICATIONS = [
        DayOffRequest::class,
        VacationRequest::class,
        InventoryRequest::class,
        SalaryQuestion::class,
        ScheduleQuestion::class,
        FeedbackSuggestion::class,
    ];

    public function count(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return collect(self::APPLICATIONS)->sum(fn (string $model) => $model::query()
            ->where('user_id', $userId)
            ->whereNull('answer_seen_at')
            ->where(function ($query) {
                $query
                    ->whereNotNull('answered_at')
                    ->orWhereNotNull('admin_comment')
                    ->orWhereIn('status', [
                        'approved',
                        'rejected',
                        'partially_approved',
                        'issued',
                        'partially_issued',
                        'cancelled',
                        'reviewed',
                        'closed',
                    ]);
            })
            ->count()
        );
    }

    public static function statusCounts(int $userId, array $availableTables): ?array
    {
        $tables = array_map(fn (string $model): string => (new $model)->getTable(), self::APPLICATIONS);
        if (array_diff($tables, $availableTables) !== []) {
            return null;
        }

        $counts = [];
        foreach (self::APPLICATIONS as $model) {
            $statuses = $model::query()->where('user_id', $userId)
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

            foreach ($statuses as $status => $total) {
                $counts[$status] = ($counts[$status] ?? 0) + (int) $total;
            }
        }

        return $counts;
    }

    public function label(?int $userId): ?string
    {
        $count = $this->count($userId);

        if ($count <= 0) {
            return null;
        }

        return $count > 9 ? '9+' : (string) $count;
    }
}

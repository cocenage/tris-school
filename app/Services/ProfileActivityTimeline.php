<?php

namespace App\Services;

use App\Models\Coaching;
use App\Models\ControlResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProfileActivityTimeline
{
    private function query(): Builder
    {
        $controls = DB::table('control_responses')->selectRaw("'control' AS item_type, id, cleaner_id, COALESCE(NULLIF(inspection_date, ''), NULLIF(cleaning_date, ''), NULLIF(sent_at, ''), created_at) AS sort_date");
        $coachings = DB::table('coachings')->selectRaw("'coaching' AS item_type, id, user_id AS cleaner_id, COALESCE(NULLIF(inspection_date, ''), created_at) AS sort_date");

        return DB::query()->fromSub($controls->unionAll($coachings), 'activities');
    }

    public function paginateForCleaner(int $cleanerId, string $type = 'all', ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->query()->where('cleaner_id', $cleanerId);
        if (in_array($type, ['control', 'coaching'], true)) {
            $query->where('item_type', $type);
        }
        $query->when($dateFrom, fn ($q) => $q->whereDate('sort_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('sort_date', '<=', $dateTo));
        $page = $query->orderByDesc('sort_date')->orderBy('item_type')->orderByDesc('id')->paginate($perPage);
        $rows = $page->getCollection();
        $models = [];
        foreach (['control' => ControlResponse::class, 'coaching' => Coaching::class] as $kind => $class) {
            $ids = $rows->where('item_type', $kind)->pluck('id');
            $models[$kind] = $ids->isEmpty() ? collect() : $class::query()->with(['cleaner', 'supervisor', 'control', 'apartment'])->whereIn('id', $ids)->get()->keyBy('id');
        }
        $page->setCollection($rows->map(function ($row) use ($models) {
            $model = $models[$row->item_type]->get($row->id);
            if ($model) {
                $model->setAttribute('item_type', $row->item_type);
                $model->setAttribute('sort_date', $row->sort_date ? Carbon::parse($row->sort_date) : null);
            }

            return $model;
        })->filter()->values());

        return $page;
    }

    public function statisticsForCleaners(array $ids, ?string $from = null): Collection
    {
        return $this->query()->whereIn('cleaner_id', $ids)
            ->when($from, fn ($q) => $q->whereDate('sort_date', '>=', $from))
            ->selectRaw("cleaner_id, SUM(CASE WHEN item_type = 'control' THEN 1 ELSE 0 END) AS controls_count, SUM(CASE WHEN item_type = 'coaching' THEN 1 ELSE 0 END) AS coachings_count, MAX(sort_date) AS last_activity_at")
            ->groupBy('cleaner_id')->get()->keyBy('cleaner_id');
    }
}

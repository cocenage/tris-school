<?php

namespace App\Services\Search;

use App\Models\AcademySearchStatistic;
use Illuminate\Support\Facades\DB;

class AcademySearchStatistics
{
    public function record(string $query, bool $hasResults): void
    {
        $query = app(AcademySearch::class)->normalize($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            return;
        }

        DB::transaction(function () use ($query, $hasResults): void {
            $hash = hash('sha256', $query);
            AcademySearchStatistic::query()->insertOrIgnore([
                'query_hash' => $hash,
                'query' => $query,
                'searches_count' => 0,
                'no_results_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AcademySearchStatistic::query()->whereKey($hash)->update([
                'searches_count' => DB::raw('searches_count + 1'),
                'no_results_count' => DB::raw('no_results_count + '.($hasResults ? '0' : '1')),
                'updated_at' => now(),
            ]);
        });
    }
}

<?php

namespace App\Services\Antigoccia;

use App\Models\AntigocciaReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteOwnAntigocciaReport
{
    /**
     * The authenticated user may remove only their own response.
     * Delete the DB record first, then its private files; keep coating dates.
     */
    public function delete(User $actor, int $reportId): void
    {
        abort_unless($actor->isApproved() && $actor->is_active, 403);

        $paths = DB::transaction(function () use ($actor, $reportId): array {
            $report = AntigocciaReport::query()
                ->whereKey($reportId)
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $prefix = 'antigoccia/'.$report->apartment_id.'/'.$report->submission_uuid.'/';
            $paths = collect($report->photos ?? [])
                ->filter(fn ($path): bool => is_string($path)
                    && str_starts_with($path, $prefix)
                    && ! str_contains($path, '..'))
                ->values()->all();

            $report->delete();

            return $paths;
        });

        if ($paths !== []) {
            try {
                if (! Storage::disk('local')->delete($paths)) {
                    Log::warning('ANTIGOCCIA report removed; private photo cleanup incomplete.', [
                        'report_id' => $reportId,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('ANTIGOCCIA report removed; private photo cleanup failed.', [
                    'report_id' => $reportId,
                    'error' => class_basename($exception),
                ]);
            }
        }
    }
}

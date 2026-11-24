<?php

namespace App\Console\Commands;

use App\Models\ControlResponseDraft;
use App\Models\FocusV2MediaUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PruneFocusV2SandboxRuns extends Command
{
    protected $signature = 'focus-v2:prune-sandbox {--days=7} {--limit=150}';

    protected $description = 'Delete expired test-only Focus V2 drafts and their private photo/video evidence';

    public function handle(): int
    {
        if (! config('focus-v2.enabled', false)) {
            $this->info('Focus V2 is disabled; sandbox cleanup skipped.');
            return self::SUCCESS;
        }

        $threshold = now()->subDays(max(1, (int) $this->option('days')));
        $limit = min(500, max(1, (int) $this->option('limit')));
        $draftIds = ControlResponseDraft::query()
            ->whereIn('state', ['sandbox', 'sandbox_complete'])
            ->where('updated_at', '<', $threshold)
            ->orderBy('updated_at')
            ->limit($limit)->pluck('id');
        $removed = 0;

        foreach ($draftIds as $id) {
            try {
                DB::transaction(function () use ($id, $threshold, &$removed): void {
                    $draft = ControlResponseDraft::query()->whereKey($id)
                        ->whereIn('state', ['sandbox', 'sandbox_complete'])
                        ->where('updated_at', '<', $threshold)
                        ->lockForUpdate()->first();
                    if (! $draft) return;

                    $uploads = FocusV2MediaUpload::query()->where('draft_id', $draft->id)
                        ->where('supervisor_id', $draft->supervisor_id)
                        ->where('run_id', $draft->run_id)
                        ->whereNull('control_response_id')->lockForUpdate()->get();
                    $disk = Storage::disk('local');

                    // Never touch real evidence paths. Only test-owned media
                    // may be removed. No mutation of ControlResponse or stats.
                    foreach ($uploads as $upload) {
                        $disk->deleteDirectory('focus-v2/uploads/'.$upload->id);
                        $prefix = 'focus-v2-sandbox/'.$draft->supervisor_id.'/'.$draft->run_id.'/';
                        $path = (string) $upload->stored_path;
                        if (str_starts_with($path, $prefix)
                            && ! str_contains($path, '..') && ! str_contains($path, '\\')) {
                            $disk->delete($path);
                        }
                        $upload->delete();
                    }
                    $draft->delete();
                    $removed++;
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Test draft {$id} could not be removed.");
            }
        }

        $this->info("Removed {$removed} expired sandbox draft(s).");
        return self::SUCCESS;
    }
}

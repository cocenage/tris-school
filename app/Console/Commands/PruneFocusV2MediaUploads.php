<?php

namespace App\Console\Commands;

use App\Models\FocusV2MediaUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PruneFocusV2MediaUploads extends Command
{
    protected $signature = 'focus-v2:prune-media {--hours=48} {--limit=200}';

    protected $description = 'Remove abandoned Focus V2 upload chunks and unattached media';

    public function handle(): int
    {
        $hours = max(24, (int) $this->option('hours'));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $threshold = now()->subHours($hours);

        $ids = FocusV2MediaUpload::query()
            ->whereIn('status', ['pending', 'ready', 'cancelled'])
            ->where('updated_at', '<', $threshold)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $threshold, &$deleted): void {
                    $upload = FocusV2MediaUpload::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $upload || ! in_array($upload->status, ['pending', 'ready', 'cancelled'], true)
                        || ! $upload->updated_at?->lt($threshold)) {
                        return;
                    }

                    $disk = Storage::disk('local');
                    $disk->deleteDirectory('focus-v2/uploads/'.$upload->id);
                    $path = (string) $upload->stored_path;
                    if ($path !== '' && str_starts_with($path, 'controls/'.$upload->control_id.'/')
                        && ! str_contains($path, '..') && ! str_contains($path, '\\')) {
                        $disk->delete($path);
                    }
                    $upload->delete();
                    $deleted++;
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Could not prune upload {$id}.");
            }
        }

        $this->info("Pruned {$deleted} abandoned Focus V2 upload(s).");

        return self::SUCCESS;
    }
}

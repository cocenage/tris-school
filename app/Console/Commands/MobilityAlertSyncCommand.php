<?php

namespace App\Console\Commands;

use App\Services\Mobility\MobilityAlertSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MobilityAlertSyncCommand extends Command
{
    protected $signature = 'mobility:sync {--dry-run : Read-only MIT strike discovery preview}';

    protected $description = 'Sync mobility alerts from external sources';

    public function handle(MobilityAlertSyncService $service): int
    {
        if ($this->option('dry-run')) {
            $service->sync(dryRun: true);
            $this->line(json_encode([
                'checked_at' => data_get($service->sourcePreview, 'checked_at'),
                'sources' => data_get($service->sourcePreview, 'sources', []),
                'events' => data_get($service->sourcePreview, 'events', []),
                'mit' => $service->strikeReport,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ($service->strikeReport['failed'] ?? 1) > 0
                || collect(data_get($service->sourcePreview, 'sources', []))->contains(fn (array $source): bool => in_array($source['status'] ?? null, ['unavailable', 'unrecognized'], true))
                ? self::FAILURE
                : self::SUCCESS;
        }

        $startedAt = Carbon::now();
        $created = $service->sync();

        $this->info("Mobility alerts sync completed. Created: {$created}");

        if ($created > 0) {
            Artisan::call('mobility:admin-alerts', [
                '--since' => $startedAt->toDateTimeString(),
            ]);
            $this->line(Artisan::output());
        }

        $this->line(json_encode($service->strikeReport, JSON_THROW_ON_ERROR));

        return ($service->strikeReport['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}

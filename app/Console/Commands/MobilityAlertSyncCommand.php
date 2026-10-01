<?php

namespace App\Console\Commands;

use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Mobility\MobilityStrikeSyncService;
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
            $counts = app(MobilityStrikeSyncService::class)->sync(dryRun: true);
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

            return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
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

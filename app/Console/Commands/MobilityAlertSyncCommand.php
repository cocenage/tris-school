<?php

namespace App\Console\Commands;

use App\Services\Mobility\MobilityAlertSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MobilityAlertSyncCommand extends Command
{
    protected $signature = 'mobility:sync';

    protected $description = 'Sync mobility alerts from external sources';

    public function handle(MobilityAlertSyncService $service): int
    {
        $startedAt = Carbon::now();
        $created = $service->sync();

        $this->info("Mobility alerts sync completed. Created: {$created}");

        if ($created > 0) {
            Artisan::call('mobility:admin-alerts', [
                '--since' => $startedAt->toDateTimeString(),
            ]);
            $this->line(Artisan::output());
        }

        return self::SUCCESS;
    }
}

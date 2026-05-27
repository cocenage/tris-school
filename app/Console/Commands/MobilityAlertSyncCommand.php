<?php

namespace App\Console\Commands;

use App\Services\Mobility\MobilityAlertSyncService;
use Illuminate\Console\Command;

class MobilityAlertSyncCommand extends Command
{
    protected $signature = 'mobility:sync';

    protected $description = 'Sync mobility alerts from external sources';

    public function handle(MobilityAlertSyncService $service): int
    {
        $created = $service->sync();

        $this->info("Mobility alerts sync completed. Created: {$created}");

        return self::SUCCESS;
    }
}
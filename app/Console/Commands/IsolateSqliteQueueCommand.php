<?php

namespace App\Console\Commands;

use App\Services\Queue\SqliteQueueIsolationService;
use Illuminate\Console\Command;
use Throwable;

class IsolateSqliteQueueCommand extends Command
{
    protected $signature = 'queue:sqlite-isolate
        {--prepare : Create the configured private queue SQLite file without overwriting anything}
        {--apply : Apply the queue row transfer instead of previewing it}
        {--reverse : Preview or reverse a transfer from sqlite_queue back to sqlite}
        {--quiesced : Confirm all queue producers and workers are stopped for an apply operation}';

    protected $description = 'Safely prepare, preview, or transfer SQLite database queue state';

    public function handle(SqliteQueueIsolationService $service): int
    {
        if ($this->option('prepare') && ($this->option('apply') || $this->option('reverse'))) {
            $this->error('queue_isolation_prepare_cannot_be_combined');

            return self::FAILURE;
        }

        if ($this->option('apply') && ! $this->option('quiesced')) {
            $this->error('queue_isolation_apply_requires_--quiesced');

            return self::FAILURE;
        }

        if (! $this->option('apply') && $this->option('quiesced')) {
            $this->error('queue_isolation_quiesced_requires_--apply');

            return self::FAILURE;
        }

        try {
            if ($this->option('prepare')) {
                $result = $service->prepare();
            } elseif ($this->option('apply')) {
                $result = $service->transfer((bool) $this->option('reverse'));
                $result['transferred'] = true;
                $result['direction'] = $this->option('reverse') ? 'sqlite_queue_to_sqlite' : 'sqlite_to_sqlite_queue';
            } else {
                $result = $service->preview((bool) $this->option('reverse'));
                $result['direction'] = $this->option('reverse') ? 'sqlite_queue_to_sqlite' : 'sqlite_to_sqlite_queue';
            }
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $this->error(str_starts_with($message, 'queue_isolation_') ? $message : 'queue_isolation_operation_failed');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return ($result['safe_to_transfer'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}

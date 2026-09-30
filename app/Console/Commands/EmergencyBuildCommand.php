<?php

namespace App\Console\Commands;

use App\Services\Emergency\EmergencyStaticSiteBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmergencyBuildCommand extends Command
{
    protected $signature = 'emergency:build';

    protected $description = 'Build the static Emergency TRIS snapshot';

    public function handle(EmergencyStaticSiteBuilder $builder): int
    {
        try {
            $result = $builder->build();
        } catch (Throwable $exception) {
            Log::error('Emergency TRIS build failed.', ['exception' => class_basename($exception)]);
            $this->error('Emergency TRIS build failed; the last successful snapshot remains unchanged. Check the application log.');

            return self::FAILURE;
        }

        $snapshot = $result['snapshot'];
        $this->info('Snapshot: '.$snapshot['snapshot_id']);
        $this->line('Generated at: '.$snapshot['generated_at']);
        $this->line('Instructions: '.$result['instruction_count']);
        $this->line('Output: '.$result['output_path']);
        $this->line('Feedback endpoint: '.($result['feedback_endpoint_configured'] ? 'configured' : 'not configured'));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Emergency\EmergencySitePublisher;
use App\Services\Emergency\EmergencyStaticSiteBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmergencyPublishCommand extends Command
{
    protected $signature = 'emergency:publish';

    protected $description = 'Build and publish the static Emergency TRIS site';

    public function handle(EmergencyStaticSiteBuilder $builder, EmergencySitePublisher $publisher): int
    {
        try {
            $build = $builder->build();
            $published = $publisher->publishLatest();
        } catch (Throwable $exception) {
            Log::error('Emergency TRIS publish failed.', ['exception' => class_basename($exception)]);
            $this->error('Emergency TRIS publish failed; the last successful published site remains available. Check the application log.');

            return self::FAILURE;
        }

        $this->info('Snapshot: '.$published['snapshot_id']);
        $this->line('Generated at: '.$build['snapshot']['generated_at']);
        $this->line('Instructions: '.$build['instruction_count']);
        $this->line('Output: '.$build['output_path']);
        $this->line('Published disk: '.$published['disk']);
        $this->line('Feedback endpoint: '.($build['feedback_endpoint_configured'] ? 'configured' : 'not configured'));

        return self::SUCCESS;
    }
}

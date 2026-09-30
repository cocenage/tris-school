<?php

namespace App\Console\Commands;

use App\Services\Emergency\EmergencyBuildPath;
use Illuminate\Console\Command;

class EmergencyStatusCommand extends Command
{
    protected $signature = 'emergency:status';

    protected $description = 'Inspect the latest Emergency TRIS build and publish status';

    public function handle(): int
    {
        $root = EmergencyBuildPath::resolve((string) config('emergency.build_path'));
        $latestPath = $root.DIRECTORY_SEPARATOR.'latest.json';
        $publishedPath = $root.DIRECTORY_SEPARATOR.'publish-status.json';
        $latest = is_file($latestPath) ? json_decode((string) file_get_contents($latestPath), true) : null;
        $published = is_file($publishedPath) ? json_decode((string) file_get_contents($publishedPath), true) : null;

        if (is_array($latest)) {
            $this->line('Snapshot: '.($latest['snapshot_id'] ?? 'unknown'));
            $this->line('Generated at: '.($latest['generated_at'] ?? 'unknown'));
            $this->line('Output: '.($root.DIRECTORY_SEPARATOR.($latest['release'] ?? '')));
        } else {
            $this->line('Build: none');
        }

        if (is_array($published)) {
            $this->line('Published snapshot: '.($published['snapshot_id'] ?? 'unknown'));
            $this->line('Published at: '.($published['published_at'] ?? 'unknown'));
            $this->line('Disk: '.($published['disk'] ?? 'unknown'));
        } else {
            $this->line('Publish: none');
        }

        return self::SUCCESS;
    }
}

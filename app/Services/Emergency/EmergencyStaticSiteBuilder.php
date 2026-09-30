<?php

namespace App\Services\Emergency;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class EmergencyStaticSiteBuilder
{
    public function __construct(private readonly EmergencySnapshotBuilder $snapshots) {}

    /** @return array{snapshot: array, output_path: string, instruction_count: int, feedback_endpoint_configured: bool} */
    public function build(): array
    {
        $snapshot = $this->snapshots->build();
        $root = EmergencyBuildPath::resolve((string) config('emergency.build_path'));

        $releasePath = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$snapshot['snapshot_id'];

        if (File::exists($releasePath)) {
            throw new RuntimeException('Emergency snapshot release path already exists.');
        }

        File::ensureDirectoryExists($releasePath.DIRECTORY_SEPARATOR.'assets');

        try {
            $json = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );
            $inlineJson = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            );
            $template = file_get_contents(resource_path('emergency/index.html'));

            if ($template === false || ! str_contains($template, '<!-- EMERGENCY_SNAPSHOT -->')) {
                throw new RuntimeException('Emergency static site template is unavailable.');
            }

            $html = str_replace('<!-- EMERGENCY_SNAPSHOT -->', $inlineJson, $template);
            $filesystem = app(Filesystem::class);
            $filesystem->put($releasePath.DIRECTORY_SEPARATOR.'index.html', $html);
            $filesystem->put($releasePath.DIRECTORY_SEPARATOR.'snapshot.json', $json."\n");
            $filesystem->copy(resource_path('emergency/assets/site.css'), $releasePath.DIRECTORY_SEPARATOR.'assets/site.css');
            $filesystem->copy(resource_path('emergency/assets/site.js'), $releasePath.DIRECTORY_SEPARATOR.'assets/site.js');

            foreach ([
                'index.html',
                'snapshot.json',
                'assets/site.css',
                'assets/site.js',
            ] as $requiredFile) {
                $path = $releasePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $requiredFile);

                if (! is_file($path) || filesize($path) === 0) {
                    throw new RuntimeException('Emergency static site bundle is incomplete.');
                }
            }

            json_decode(file_get_contents($releasePath.DIRECTORY_SEPARATOR.'snapshot.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (! str_contains((string) file_get_contents($releasePath.DIRECTORY_SEPARATOR.'index.html'), $snapshot['snapshot_id'])) {
                throw new RuntimeException('Emergency static site validation failed.');
            }

            $pointer = json_encode([
                'snapshot_id' => $snapshot['snapshot_id'],
                'generated_at' => $snapshot['generated_at'],
                'release' => 'releases/'.$snapshot['snapshot_id'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $filesystem->replace($root.DIRECTORY_SEPARATOR.'latest.json', $pointer."\n");
            if (file_get_contents($root.DIRECTORY_SEPARATOR.'latest.json') !== $pointer."\n") {
                throw new RuntimeException('Emergency snapshot pointer could not be updated.');
            }
        } catch (JsonException $exception) {
            throw new RuntimeException('Emergency snapshot serialization failed.', previous: $exception);
        }

        return [
            'snapshot' => $snapshot,
            'output_path' => $releasePath,
            'instruction_count' => collect($snapshot['instructions'])->sum(fn (array $group): int => count($group['instructions'])),
            'feedback_endpoint_configured' => filled($snapshot['feedback']['endpoint']),
        ];
    }
}

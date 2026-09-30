<?php

namespace App\Services\Emergency;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EmergencySitePublisher
{
    public function __construct(private readonly EmergencyPublicationTarget $target) {}

    /** @return array{snapshot_id: string, disk: string, published_at: string, prefix: string} */
    public function publishLatest(): array
    {
        if (! $this->target->isConfigured()) {
            throw new RuntimeException('No usable Emergency TRIS publication disk is configured.');
        }

        $diskName = (string) $this->target->diskName();
        $root = EmergencyBuildPath::resolve((string) config('emergency.build_path'));
        $pointer = json_decode((string) @file_get_contents($root.DIRECTORY_SEPARATOR.'latest.json'), true);
        $snapshotId = is_array($pointer) ? (string) ($pointer['snapshot_id'] ?? '') : '';

        if (! preg_match('/^emergency-[A-Za-z0-9T+_.-]+-[a-f0-9]{12}$/', $snapshotId)) {
            throw new RuntimeException('No validated Emergency TRIS build is available to publish.');
        }

        $release = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$snapshotId;
        if (! File::isDirectory($release)) {
            throw new RuntimeException('The latest Emergency TRIS release directory is missing.');
        }

        $prefix = $this->target->prefix();
        $releasePrefix = trim($prefix.'/releases/'.$snapshotId, '/');
        $disk = Storage::disk($diskName);
        $files = File::allFiles($release);

        if ($files === []) {
            throw new RuntimeException('The Emergency TRIS release is empty.');
        }

        try {
            foreach ($files as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
                $targetPath = $releasePrefix.'/'.$relative;
                if (! $disk->put($targetPath, file_get_contents($file->getPathname()))) {
                    throw new RuntimeException('An Emergency TRIS release file could not be uploaded.');
                }
            }

            $releaseUrl = 'releases/'.$snapshotId.'/index.html';
            $pointerHtml = $this->pointerHtml($releaseUrl, $snapshotId);
            $indexPath = trim($prefix.'/index.html', '/');

            // Publish the stable entry point last; all release files are immutable/versioned.
            if (! $disk->put($indexPath, $pointerHtml)) {
                throw new RuntimeException('The Emergency TRIS site pointer could not be published.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Emergency TRIS publish failed; the previous site pointer was preserved.', previous: $exception);
        }

        $publishedAt = now(config('app.timezone', 'Europe/Rome'))->toIso8601String();
        $result = [
            'snapshot_id' => $snapshotId,
            'disk' => $diskName,
            'published_at' => $publishedAt,
            'prefix' => $prefix,
        ];
        $statePath = $root.DIRECTORY_SEPARATOR.'publish-status.json';
        try {
            app(Filesystem::class)->replace($statePath, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $exception) {
            Log::warning('Emergency TRIS published, but local publish status could not be updated.', [
                'exception' => class_basename($exception),
            ]);
        }

        return $result;
    }

    private function pointerHtml(string $releaseUrl, string $snapshotId): string
    {
        $escapedUrl = htmlspecialchars($releaseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedId = htmlspecialchars($snapshotId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta http-equiv="refresh" content="0;url='.$escapedUrl.'"><title>Emergency TRIS</title>'
            .'<p>Открывается актуальный аварийный пакет '.$escapedId.'. <a href="'.$escapedUrl.'">Продолжить</a></p></html>';
    }
}

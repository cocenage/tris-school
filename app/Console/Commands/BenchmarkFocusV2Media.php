<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BenchmarkFocusV2Media extends Command
{
    protected $signature = 'focus-v2:benchmark-media {--mb=16 : Synthetic MiB to assemble (1..64)}
        {--chunk-kb=1024 : Chunk size to compare: 512 or 1024 KiB}';

    protected $description = 'Measure local disk chunk assembly without any production media, users or database changes';

    public function handle(): int
    {
        $mb = (int) $this->option('mb');
        $chunkKb = (int) $this->option('chunk-kb');
        if ($mb < 1 || $mb > 64 || ! in_array($chunkKb, [512, 1024], true)) {
            $this->error('Use --mb=1..64 and --chunk-kb=512 or 1024.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $directory = 'focus-v2-bench/'.bin2hex(random_bytes(8));
        $bytes = $mb * 1024 * 1024;
        $chunkBytes = $chunkKb * 1024;
        $total = (int) ceil($bytes / $chunkBytes);
        $disk->makeDirectory($directory);
        $sample = str_repeat('F', $chunkBytes);
        $assemblyStart = 0;
        $assembled = 0;

        try {
            for ($i = 0; $i < $total; $i++) {
                $expected = min($chunkBytes, $bytes - $i * $chunkBytes);
                if (! $disk->put($directory.'/'.$i.'.part', substr($sample, 0, $expected))) {
                    throw new \RuntimeException('Synthetic chunk creation failed.');
                }
            }

            $destinationPath = $disk->path($directory.'/assembled.bin');
            $destination = fopen($destinationPath, 'wb');
            if ($destination === false) {
                throw new \RuntimeException('Synthetic destination cannot be created.');
            }
            $assemblyStart = hrtime(true);
            try {
                for ($i = 0; $i < $total; $i++) {
                    $path = $disk->path($directory.'/'.$i.'.part');
                    $expected = min($chunkBytes, $bytes - $i * $chunkBytes);
                    if (! is_file($path) || filesize($path) !== $expected) {
                        throw new \RuntimeException('Synthetic chunk verification failed.');
                    }
                    $source = fopen($path, 'rb');
                    if ($source === false) {
                        throw new \RuntimeException('Synthetic chunk read failed.');
                    }
                    try {
                        $copied = stream_copy_to_stream($source, $destination);
                        if ($copied !== $expected) {
                            throw new \RuntimeException('Synthetic chunk copy failed.');
                        }
                        $assembled += $copied;
                    } finally {
                        fclose($source);
                    }
                }
            } finally {
                fclose($destination);
            }
            if ($assembled !== $bytes || filesize($destinationPath) !== $bytes) {
                throw new \RuntimeException('Synthetic assembly was incomplete.');
            }

            $elapsedMs = max(0.001, (hrtime(true) - $assemblyStart) / 1_000_000);
            $this->info(sprintf(
                'Synthetic assembly: %d MiB, %d chunks of %d KiB, %.1f ms, %.1f MiB/s, peak PHP %.1f MiB.',
                $mb, $total, $chunkKb, $elapsedMs, $mb / ($elapsedMs / 1000),
                memory_get_peak_usage(true) / 1048576,
            ));
            $this->line('Disk and PHP throughput only. This is not an end-to-end Telegram/network benchmark.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Synthetic assembly failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            // Hard-coded prefix is private to this command; never delete
            // actual uploads under controls/ or focus-v2/uploads/.
            $disk->deleteDirectory($directory);
        }
    }
}

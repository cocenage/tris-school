<?php

namespace App\Services\Emergency;

class EmergencyPublicationTarget
{
    public function diskName(): ?string
    {
        $name = trim((string) config('emergency.publication_disk'));

        return $name !== '' ? $name : null;
    }

    public function isConfigured(): bool
    {
        $name = $this->diskName();
        $disk = $name ? config('filesystems.disks.'.$name) : null;

        if (! is_array($disk) || blank($disk['driver'] ?? null)) {
            return false;
        }

        return match ($disk['driver']) {
            'local' => filled($disk['root'] ?? null),
            's3' => filled($disk['bucket'] ?? null)
                && class_exists(\League\Flysystem\AwsS3V3\PortableVisibilityConverter::class),
            default => false,
        };
    }

    public function prefix(): string
    {
        $prefix = str_replace('\\', '/', trim((string) config('emergency.publication_prefix', ''), '/\\'));

        if ($prefix !== '' && collect(explode('/', $prefix))
            ->contains(fn (string $part): bool => in_array($part, ['', '.', '..'], true))) {
            throw new \RuntimeException('Emergency publication prefix is invalid.');
        }

        return $prefix;
    }
}

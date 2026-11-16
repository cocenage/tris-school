<?php

namespace App\Services\Controls;

use Illuminate\Support\Facades\Storage;
use Throwable;

final class ControlImageThumbnail
{
    public function path(string $source, string $mime): ?string
    {
        if (! extension_loaded('gd') || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $disk = Storage::disk('local');
        $revision = filemtime($source).':'.filesize($source);
        $key = 'control-thumbnails/v1/'.hash('sha256', $source.':'.$revision).'.jpg';
        if ($disk->exists($key)) {
            return $disk->path($key);
        }

        try {
            $size = @getimagesize($source);
            if ($size === false || $size[0] * $size[1] > 24_000_000 || $size['mime'] !== $mime) {
                return null;
            }

            $image = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($source),
                'image/png' => @imagecreatefrompng($source),
                'image/webp' => @imagecreatefromwebp($source),
            };
            if ($image === false) {
                return null;
            }

            $ratio = min(1, 360 / max($size[0], $size[1]));
            $width = max(1, (int) round($size[0] * $ratio));
            $height = max(1, (int) round($size[1] * $ratio));
            $thumbnail = imagecreatetruecolor($width, $height);
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 250, 250, 250));
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

            $disk->makeDirectory(dirname($key));
            $target = $disk->path($key);
            $temporary = tempnam(dirname($target), 'preview-');
            if ($temporary === false) {
                return null;
            }

            try {
                return imagejpeg($thumbnail, $temporary, 76) && rename($temporary, $target)
                    ? $target
                    : null;
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        } catch (Throwable) {
            return null;
        }
    }
}

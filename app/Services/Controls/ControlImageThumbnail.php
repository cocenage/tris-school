<?php

namespace App\Services\Controls;

use Illuminate\Support\Facades\Storage;
use Throwable;

final class ControlImageThumbnail
{
    public function path(string $source, string $mime): ?string
    {
        // HEIC/HEIF is common on iPhones and unsupported by PHP GD.
        // Only attempt conversion if ImageMagick's HEIC delegate is available.
        // A failed conversion must never expose a broken thumbnail or the
        // original private file to an unrelated viewer.
        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            return $this->heicPath($source);
        }
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
    private function heicPath(string $source): ?string
    {
        if (! class_exists(\Imagick::class) || ! extension_loaded('imagick') || ! is_file($source)
            || filesize($source) === false || filesize($source) > 10 * 1024 * 1024) {
            return null;
        }

        try {
            // Not every Imagick installation includes libheif. If unavailable,
            // the caller displays a safe placeholder instead of broken media.
            $supported = array_merge(\Imagick::queryFormats('HEIC'), \Imagick::queryFormats('HEIF'));
            if ($supported === []) {
                return null;
            }

            $image = new \Imagick;
            $image->pingImage($source);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width < 1 || $height < 1 || $width * $height > 24_000_000) {
                $image->clear();

                return null;
            }

            // ImageMagick can be configured to reject hostile HEIC bitstreams.
            // The file is already validated and stored in the private disk.
            $image->clear();
            $image->readImage($source.'[0]');
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(76);
            $image->thumbnailImage(360, 360, true, true);
            $revision = filemtime($source).':'.filesize($source);
            $key = 'control-thumbnails/v1/'.hash('sha256', $source.':'.$revision).'.jpg';
            $disk = Storage::disk('local');
            if ($disk->exists($key)) {
                $image->clear();

                return $disk->path($key);
            }
            $disk->makeDirectory(dirname($key));
            $temp = tempnam(dirname($disk->path($key)), 'heic-');
            if ($temp === false) {
                $image->clear();

                return null;
            }
            try {
                $ok = $image->writeImage($temp) && rename($temp, $disk->path($key));
                $image->clear();

                return $ok ? $disk->path($key) : null;
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        } catch (Throwable) {
            return null;
        }
    }

}

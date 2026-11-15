<?php

namespace App\Services\Controls;

final class FocusMediaLimits
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public const IMAGE_KIB = 10 * 1024;

    public const VIDEO_KIB = 100 * 1024;

    public static function transportBytes(): int
    {
        $limit = self::VIDEO_KIB * 1024;
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $configured = ini_parse_quantity((string) ini_get($setting));
            if ($configured > 0) {
                $limit = min($limit, max(1, $configured - ($setting === 'post_max_size' ? 65536 : 0)));
            }
        }

        return $limit;
    }
}

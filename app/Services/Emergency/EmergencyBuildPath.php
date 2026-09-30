<?php

namespace App\Services\Emergency;

class EmergencyBuildPath
{
    public static function resolve(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }
}

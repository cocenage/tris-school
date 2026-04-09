<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramAvatarService
{
    public function sanitizeMiniAppPhotoUrl(?string $photoUrl): ?string
    {
        if (! filled($photoUrl)) {
            return null;
        }

        $url = strtolower($photoUrl);

        if (
            str_contains($url, '/i/userpic/')
            || str_ends_with($url, '.svg')
        ) {
            return null;
        }

        return $photoUrl;
    }

    public function downloadUserAvatar(string|int $telegramId): ?string
    {
        $token = config('services.telegram.bot_token');

        if (! filled($token)) {
            Log::warning('Telegram bot token is missing.');
            return null;
        }

        try {
            $photosResponse = Http::timeout(15)->get(
                "https://api.telegram.org/bot{$token}/getUserProfilePhotos",
                [
                    'user_id' => $telegramId,
                    'limit' => 1,
                ]
            );

            $photosJson = $photosResponse->json();

            Log::info('Telegram getUserProfilePhotos response', [
                'telegram_id' => $telegramId,
                'ok' => $photosResponse->ok(),
                'response' => $photosJson,
            ]);

            if (! $photosResponse->ok()) {
                return null;
            }

            $sizes = data_get($photosJson, 'result.photos.0');

            if (! is_array($sizes) || empty($sizes)) {
                return null;
            }

            $best = collect($sizes)
                ->filter(fn ($item) => ! empty($item['file_id']))
                ->sortByDesc(fn ($item) => (($item['width'] ?? 0) * ($item['height'] ?? 0)) + ($item['file_size'] ?? 0))
                ->first();

            if (! $best || empty($best['file_id'])) {
                return null;
            }

            $fileResponse = Http::timeout(15)->get(
                "https://api.telegram.org/bot{$token}/getFile",
                [
                    'file_id' => $best['file_id'],
                ]
            );

            $fileJson = $fileResponse->json();

            Log::info('Telegram getFile response', [
                'telegram_id' => $telegramId,
                'ok' => $fileResponse->ok(),
                'response' => $fileJson,
            ]);

            if (! $fileResponse->ok()) {
                return null;
            }

            $filePath = data_get($fileJson, 'result.file_path');

            if (! filled($filePath)) {
                return null;
            }

            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
            $imageResponse = Http::timeout(20)->get($downloadUrl);

            Log::info('Telegram avatar download response', [
                'telegram_id' => $telegramId,
                'ok' => $imageResponse->ok(),
                'file_path' => $filePath,
                'content_type' => $imageResponse->header('Content-Type'),
            ]);

            if (! $imageResponse->ok()) {
                return null;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg');

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'jpg';
            }

            $storagePath = "telegram-avatars/{$telegramId}.{$extension}";

            Storage::disk('public')->put($storagePath, $imageResponse->body());

            return $storagePath;
        } catch (\Throwable $e) {
            Log::error('Telegram avatar download failed', [
                'telegram_id' => $telegramId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
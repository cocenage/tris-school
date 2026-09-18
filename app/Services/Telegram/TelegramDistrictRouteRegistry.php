<?php

namespace App\Services\Telegram;

use Illuminate\Support\Collection;

class TelegramDistrictRouteRegistry
{
    /** @return Collection<int, array<string, mixed>> */
    public function routes(): Collection
    {
        return collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $route, mixed $key): array => $this->normalize((string) $key, $route))
            ->filter(fn (array $route): bool => $route['valid'])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function diagnostics(): Collection
    {
        return collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $route, mixed $key): array => $this->normalize((string) $key, $route))
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $normalized = mb_strtolower(trim($key));

        return $this->routes()->first(
            fn (array $route): bool => $route['key'] === $normalized,
        );
    }

    /** @return array<string, mixed> */
    private function normalize(string $key, mixed $value): array
    {
        $route = is_array($value) ? $value : [];
        $errors = [];
        $key = mb_strtolower(trim($key));
        $label = trim((string) ($route['label'] ?? ''));
        $chatId = trim((string) ($route['chat_id'] ?? ''));
        $threadId = trim((string) ($route['duty_thread_id'] ?? ''));
        $latitude = filter_var($route['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($route['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($key === '') {
            $errors[] = 'missing_key';
        }
        if ($label === '') {
            $errors[] = 'missing_label';
        }
        if ($chatId === '') {
            $errors[] = 'missing_chat_id';
        }
        if ($threadId === '') {
            $errors[] = 'missing_duty_thread_id';
        }
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $errors[] = 'invalid_latitude';
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $errors[] = 'invalid_longitude';
        }

        return [
            'key' => $key,
            'label' => $label,
            'chat_id' => $chatId !== '' ? $chatId : null,
            'duty_thread_id' => $threadId !== '' ? $threadId : null,
            'latitude' => $latitude !== false ? (float) $latitude : null,
            'longitude' => $longitude !== false ? (float) $longitude : null,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}

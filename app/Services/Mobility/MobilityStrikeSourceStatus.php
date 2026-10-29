<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class MobilityStrikeSourceStatus
{
    private const CACHE_KEY = 'mobility.strike-source-status';

    private const MAX_AGE_MINUTES = 90;

    private const SOURCES = [
        'mit' => 'MIT',
        'atm' => 'ATM Milano',
        'trenord_news' => 'Trenord — новости',
        'trenord_strikes' => 'Trenord — забастовки',
        'trenord_home' => 'Trenord — сайт',
        'telegram_undergroundstatus' => 'Metro status',
    ];

    public function recordRun(array $sources, ?CarbonImmutable $checkedAt = null): void
    {
        $checkedAt ??= CarbonImmutable::now('Europe/Rome');
        $previous = Cache::get(self::CACHE_KEY, []);
        $statuses = [];
        foreach (self::SOURCES as $key => $label) {
            $raw = $sources[$key] ?? false;
            $status = is_array($raw)
                ? (string) ($raw['status'] ?? 'unavailable')
                : ((bool) $raw ? 'checked' : 'unavailable');
            $available = in_array($status, ['checked', 'checked_empty', 'events_found'], true);
            $lastSuccessfulAt = $available
                ? (string) (data_get($raw, 'last_successful_at') ?: $checkedAt->toIso8601String())
                : data_get($previous, 'sources.'.$key.'.last_successful_at');

            $statuses[$key] = [
                'label' => $label,
                'available' => $available,
                'status' => $status,
                'event_count' => is_array($raw) ? max(0, (int) ($raw['event_count'] ?? 0)) : 0,
                'last_successful_at' => $lastSuccessfulAt,
                'issue' => is_array($raw) ? ($raw['issue'] ?? null) : null,
            ];
        }

        Cache::put(self::CACHE_KEY, [
            'checked_at' => $checkedAt->toIso8601String(),
            'sources' => $statuses,
        ], now()->addDay());
    }

    public function forReport(): array
    {
        try {
            $run = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            return $this->unknownStatus();
        }

        if (! is_array($run) || ! is_string($run['checked_at'] ?? null) || ! is_array($run['sources'] ?? null)) {
            return $this->unknownStatus();
        }

        try {
            $checkedAt = CarbonImmutable::parse($run['checked_at'])->setTimezone('Europe/Rome');
        } catch (\Throwable) {
            return $this->unknownStatus();
        }

        $now = CarbonImmutable::now('Europe/Rome');
        if ($checkedAt->isFuture() || $checkedAt->lt($now->subMinutes(self::MAX_AGE_MINUTES))) {
            return [
                'state' => 'stale',
                'checked_at' => $checkedAt->toIso8601String(),
                'sources' => $this->normalizeSources($run['sources']),
                'unavailable' => [],
            ];
        }

        $sources = $this->normalizeSources($run['sources']);
        $unavailable = collect($sources)
            ->filter(fn (array $source): bool => ! $source['available'])
            ->pluck('label')
            ->values()
            ->all();

        return [
            'state' => $unavailable === [] ? 'complete' : 'partial',
            'checked_at' => $checkedAt->toIso8601String(),
            'sources' => $sources,
            'unavailable' => $unavailable,
        ];
    }

    private function unknownStatus(): array
    {
        return [
            'state' => 'unknown',
            'checked_at' => null,
            'sources' => collect(self::SOURCES)->map(fn (string $label): array => [
                'label' => $label,
                'available' => null,
                'status' => 'unknown',
                'event_count' => null,
                'last_successful_at' => null,
                'issue' => null,
            ])->all(),
            'unavailable' => array_values(self::SOURCES),
        ];
    }

    private function normalizeSources(array $sources): array
    {
        $normalized = [];
        foreach (self::SOURCES as $key => $label) {
            $available = data_get($sources, $key.'.available', data_get($sources, $key));
            $status = data_get($sources, $key.'.status');
            if (! is_string($status)) {
                $status = $available === true ? 'checked' : ($available === false ? 'unavailable' : 'unknown');
            }
            $normalized[$key] = [
                'label' => $label,
                'available' => in_array($status, ['checked', 'checked_empty', 'events_found'], true)
                    ? true
                    : (in_array($status, ['unavailable', 'unrecognized'], true) ? false : null),
                'status' => $status,
                'event_count' => data_get($sources, $key.'.event_count'),
                'last_successful_at' => data_get($sources, $key.'.last_successful_at'),
                'issue' => data_get($sources, $key.'.issue'),
            ];
        }

        return $normalized;
    }
}

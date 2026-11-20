<?php

namespace App\Services\Mobility;

class MobilityStrikeCoverage
{
    private const MILAN = ['navigli', 'lodi', 'certosa', 'lambrate'];

    private const TRIS = [...self::MILAN, 'como'];

    /** @return list<string> */
    public function districts(string $source, array $item, ?string $location = null): array
    {
        $mode = $this->transportMode($source, $item);
        $region = mb_strtolower(trim((string) ($item['region'] ?? '')));
        if ($mode === null || (! in_array($region, ['', 'italia', 'tutte', 'tutte le regioni'], true) && ! preg_match('/\blombardia\b/u', $region))) {
            return [];
        }

        $operator = $this->operator($source, $item);
        $network = $operator === 'ATM Milano' ? self::MILAN : self::TRIS;
        $province = mb_strtolower(trim((string) ($item['province'] ?? '')));
        if (! in_array($province, ['', 'tutte', 'tutti', '-', 'tutte le province'], true)) {
            $local = [
                ...(preg_match('/\b(?:milano|milan)\b/u', $province) ? self::MILAN : []),
                ...(preg_match('/\bcomo\b/u', $province) ? ['como'] : []),
            ];

            return array_values(array_intersect($network, $local));
        }

        $scope = mb_strtolower(trim((string) ($item['scope'] ?? '')));
        if (in_array($source, ['atm', 'trenord'], true) || $scope === 'nazionale') {
            return $network;
        }

        $location = mb_strtolower(trim((string) $location));
        if (in_array($location, self::TRIS, true)) {
            return array_values(array_intersect($network, [$location]));
        }
        if (in_array($location, ['milano', 'milan'], true) || preg_match('/^m[1-5]$/u', $location)) {
            return array_values(array_intersect($network, self::MILAN));
        }

        return $region !== '' || in_array($location, ['lombardia', 'italia'], true) || $operator !== null
            ? $network
            : [];
    }

    public function transportMode(string $source, array $item): ?string
    {
        $sector = mb_strtolower((string) ($item['sector'] ?? ''));
        $operator = $this->operator($source, $item);

        return match (true) {
            $operator === 'Trenord', str_contains($sector, 'ferroviar') => 'rail',
            $operator === 'ATM Milano', str_contains($sector, 'trasporto pubblico locale') => 'local',
            default => null,
        };
    }

    public function operator(string $source, array $item): ?string
    {
        $operator = mb_strtolower((string) ($item['operator'] ?? ''));

        return match (true) {
            $source === 'atm', preg_match('/\batm\b/u', $operator) === 1 => 'ATM Milano',
            $source === 'trenord', preg_match('/\btrenord\b/u', $operator) === 1 => 'Trenord',
            default => null,
        };
    }
}

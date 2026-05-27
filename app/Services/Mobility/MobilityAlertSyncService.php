<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MobilityAlertSyncService
{
    public function sync(): int
    {
        $created = 0;

        foreach ($this->sources() as $source => $url) {
            $created += $this->syncSource($source, $url);
        }

        return $created;
    }

    protected function sources(): array
    {
        return [
            'atm' => 'https://www.atm.it/it/ViaggiaConNoi/InfoTraffico/',
            'comune' => 'https://www.comune.milano.it/home/infomobilita',
            'luceverde' => 'https://milano.luceverde.it/news',
            'trenord' => 'https://www.trenord.it/news/trenord-informa/avvisi/',
        ];
    }

protected function syncSource(string $source, string $url): int
{
    try {
        $response = Http::timeout(20)
            ->retry(2, 1000)
            ->withoutVerifying()
            ->withHeaders([
                'User-Agent' => 'TRIS Mobility Alert Bot',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
            ->get($url);
    } catch (\Throwable $e) {
        logger()->warning('Mobility source request failed', [
            'source' => $source,
            'url' => $url,
            'error' => $e->getMessage(),
        ]);

        return 0;
    }

    if (! $response->successful()) {
        logger()->warning('Mobility source returned bad status', [
            'source' => $source,
            'url' => $url,
            'status' => $response->status(),
        ]);

        return 0;
    }

    $items = $this->extractLinks($url, $response->body());

    $created = 0;

    foreach ($items as $item) {
        if (! $this->looksUseful($item['title'])) {
            continue;
        }

        $hash = sha1($source . '|' . $item['title'] . '|' . $item['url']);

        $alert = MobilityAlert::firstOrCreate(
            ['external_hash' => $hash],
            [
                'source' => $source,
                'title' => $item['title'],
                'description' => $item['description'] ?? null,
                'url' => $item['url'],
                'type' => $this->detectType($item['title']),
                'risk' => $this->detectRisk($item['title']),
                'district' => $this->detectDistrict($item['title']),
                'starts_at' => $this->extractDate($item['title']) ?? now()->addDay()->startOfDay(),
'ends_at' => null,
        
            ]
        );

        if ($alert->wasRecentlyCreated) {
            $created++;
        }
    }

    return $created;
}

    protected function extractLinks(string $baseUrl, string $html): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        $items = [];

        foreach ($matches as $match) {
            $href = html_entity_decode($match[1]);
            $title = trim(strip_tags($match[2]));
            $title = preg_replace('/\s+/', ' ', $title);

            if (mb_strlen($title) < 8) {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => null,
                'url' => $this->absoluteUrl($baseUrl, $href),
            ];
        }

        return array_slice($items, 0, 60);
    }

    protected function absoluteUrl(string $baseUrl, string $href): string
    {
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $parts = parse_url($baseUrl);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (Str::startsWith($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
    }

protected function looksUseful(string $title): bool
{
    $text = mb_strtolower($title);

    /*
    |--------------------------------------------------------------------------
    | Игнорируем локальный мусор
    |--------------------------------------------------------------------------
    */

    $ignorePatterns = [
        '/^bus\s\d+/u',
        '/^tram\s\d+/u',
        '/fermata/u',
        '/fermate/u',
        '/deviazione/u',
        '/deviano/u',
        '/spostata/u',
    ];

    foreach ($ignorePatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Важные события
    |--------------------------------------------------------------------------
    */

    $importantKeywords = [
        'sciopero',

        'manifestazione',
        'manifestazioni',

        'evento',
        'eventi',

        'concerto',
        'concerti',

        'san siro',

        'fashion week',
        'salone del mobile',

        'marathon',
        'maratona',

        'chiude',
        'chiusura',

        'metro',

        'm1',
        'm2',
        'm3',
        'm4',

        'trenord',

        'stazione',

        'circolazione',

        'viabilità',

        'traffico',

        'lavori',

        'cambiamenti programmati',
    ];

    foreach ($importantKeywords as $keyword) {
        if (str_contains($text, $keyword)) {
            return true;
        }
    }

    return false;
}

    protected function detectType(string $title): string
    {
        $text = mb_strtolower($title);

        return match (true) {
            str_contains($text, 'sciopero') => 'strike',
            str_contains($text, 'concerto') => 'event',
            str_contains($text, 'san siro') => 'event',
            str_contains($text, 'lavori') => 'roadwork',
            str_contains($text, 'chiusura') => 'roadwork',
            default => 'transport',
        };
    }

    protected function detectRisk(string $title): string
    {
        $text = mb_strtolower($title);

        if (
            str_contains($text, 'sciopero') ||
            str_contains($text, 'san siro') ||
            str_contains($text, 'manifestazione') ||
            str_contains($text, 'chiusura')
        ) {
            return 'high';
        }

        if (
            str_contains($text, 'modifiche') ||
            str_contains($text, 'cambiamenti') ||
            str_contains($text, 'lavori')
        ) {
            return 'medium';
        }

        return 'low';
    }

    protected function detectDistrict(string $title): ?string
    {
        $text = mb_strtolower($title);

        $districts = [
            'duomo' => 'Duomo',
            'centrale' => 'Centrale',
            'garibaldi' => 'Garibaldi',
            'cadorna' => 'Cadorna',
            'citylife' => 'CityLife',
            'san siro' => 'San Siro',
            'navigli' => 'Navigli',
            'loreto' => 'Loreto',
            'porta venezia' => 'Porta Venezia',
            'porta romana' => 'Porta Romana',
            'lambrate' => 'Lambrate',
        ];

        foreach ($districts as $needle => $district) {
            if (str_contains($text, $needle)) {
                return $district;
            }
        }

        return null;
    }

    protected function extractDate(string $text): ?Carbon
    {
        $months = [
            'gennaio' => 1,
            'febbraio' => 2,
            'marzo' => 3,
            'aprile' => 4,
            'maggio' => 5,
            'giugno' => 6,
            'luglio' => 7,
            'agosto' => 8,
            'settembre' => 9,
            'ottobre' => 10,
            'novembre' => 11,
            'dicembre' => 12,
        ];

        $lower = mb_strtolower($text);

        foreach ($months as $monthName => $monthNumber) {
            if (preg_match('/(\d{1,2})\s+' . $monthName . '/u', $lower, $match)) {
                return Carbon::create(now()->year, $monthNumber, (int) $match[1])->startOfDay();
            }
        }

        if (preg_match('/(\d{1,2})\/(\d{1,2})/u', $lower, $match)) {
            return Carbon::create(now()->year, (int) $match[2], (int) $match[1])->startOfDay();
        }

        return null;
    }
}
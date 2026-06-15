<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobilityAlertSyncService
{
    public function sync(): int
    {
        return $this->syncMitStrikes()
            + $this->syncAtm()
            + $this->syncTrenord();
    }

    protected function syncMitStrikes(): int
    {
        return $this->syncGenericPage(
            source: 'mit',
            url: 'https://scioperi.mit.gov.it/mit2/public/scioperi',
            forcedType: 'strike',
            forcedRisk: 'high'
        );
    }

    protected function syncAtm(): int
    {
        return $this->syncGenericPage(
            source: 'atm',
            url: 'https://www.atm.it/it/Pagine/default.aspx'
        );
    }

    protected function syncTrenord(): int
    {
        $created = 0;

        $urls = [
            'https://www.trenord.it/news/',
            'https://www.trenord.it/assistenza/informazioni-utili/in-caso-di-sciopero/',
            'https://www.trenord.it/',
        ];

        foreach ($urls as $url) {
            $created += $this->syncGenericPage(
                source: 'trenord',
                url: $url
            );
        }

        return $created;
    }

    protected function syncGenericPage(
        string $source,
        string $url,
        ?string $forcedType = null,
        ?string $forcedRisk = null
    ): int {
        try {
            $response = Http::withoutVerifying()
                ->connectTimeout(10)
                ->timeout(20)
                ->retry(1, 1000)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; TRIS Mobility Alert Bot)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Mobility source request failed', [
                'source' => $source,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('Mobility source returned bad status', [
                'source' => $source,
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => Str::limit($response->body(), 500),
            ]);

            return 0;
        }

        $html = $response->body();

        Log::info('Mobility source loaded', [
            'source' => $source,
            'url' => $url,
            'length' => strlen($html),
        ]);

        $items = $this->extractLinks($url, $html);

        Log::info('Mobility source links extracted', [
            'source' => $source,
            'url' => $url,
            'count' => count($items),
        ]);

        $created = 0;

        foreach ($items as $item) {
            $title = $this->cleanTitle($item['title']);

            if (! $this->looksUseful($title, $source)) {
                continue;
            }

            $eventDate = $this->extractDate($title) ?? now()->startOfDay();

            $hash = sha1($source . '|' . $title . '|' . $item['url']);

            $alert = MobilityAlert::firstOrCreate(
                ['external_hash' => $hash],
                [
                    'source' => $source,
                    'title' => $title,
                    'description' => null,
                    'url' => $item['url'],
                    'type' => $forcedType ?? $this->detectType($title, $source),
                    'risk' => $forcedRisk ?? $this->detectRisk($title, $source),
                    'district' => $this->detectDistrict($title),
                    'starts_at' => $eventDate,
                    'ends_at' => null,
                ]
            );

            if ($alert->wasRecentlyCreated) {
                $created++;
            }
        }

        Log::info('Mobility source finished', [
            'source' => $source,
            'url' => $url,
            'created' => $created,
        ]);

        return $created;
    }

    protected function extractLinks(string $baseUrl, string $html): array
    {
        preg_match_all(
            '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $items = [];

        foreach ($matches as $match) {
            $href = html_entity_decode($match[1]);
            $title = $this->cleanTitle(strip_tags($match[2]));

            if (mb_strlen($title) < 8) {
                continue;
            }

            $items[] = [
                'title' => $title,
                'url' => $this->absoluteUrl($baseUrl, $href),
            ];
        }

        return collect($items)
            ->unique(fn ($item) => $item['title'] . '|' . $item['url'])
            ->take(120)
            ->values()
            ->all();
    }

    protected function cleanTitle(string $title): string
    {
        $title = html_entity_decode($title);
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
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

    protected function looksUseful(string $title, string $source): bool
    {
        $text = mb_strtolower($title);

        $trashKeywords = [
            'manifestazione di interesse',
            'manifestazioni di interesse',
            'imprese e fornitori',
            'impreseefornitori',
            'fornitori',
            'vendita immobili',
            'vendita',
            'affitto',
            'fibre ottiche',
            'mappa metro',
            'metro maps',
            'mappa',
            'biglietti',
            'abbonamenti',
            'lavora con noi',
            'gare',
            'bandi',
            'appalti',
            'privacy',
            'cookie',
            'contatti',
            'newsletter',
        ];

        foreach ($trashKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return false;
            }
        }

        if ($source === 'mit') {
            return str_contains($text, 'sciopero')
                || str_contains($text, 'scioperi')
                || str_contains($text, 'lombardia')
                || str_contains($text, 'milano')
                || str_contains($text, 'trasporto pubblico')
                || str_contains($text, 'trasporto ferroviario')
                || str_contains($text, 'ferroviario')
                || str_contains($text, 'atm')
                || str_contains($text, 'trenord');
        }

        if ($source === 'trenord') {
            return str_contains($text, 'sciopero')
                || str_contains($text, 'scioperi')
                || str_contains($text, 'agitazione')
                || str_contains($text, 'circolazione')
                || str_contains($text, 'lombardia')
                || str_contains($text, 'milano')
                || str_contains($text, 'treni')
                || str_contains($text, 'ferroviario')
                || str_contains($text, 'trenord');
        }

        $ignorePatterns = [
            '/^bus\s\d+/u',
            '/^tram\s\d+/u',
            '/^filobus\s\d+/u',
            '/fermata/u',
            '/fermate/u',
            '/deviazione/u',
            '/deviazioni/u',
            '/deviano/u',
            '/spostata/u',
            '/sospesa/u',
            '/sospese/u',
        ];

        foreach ($ignorePatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        $importantKeywords = [
            'sciopero',
            'scioperi',
            'manifestazione',
            'manifestazioni',
            'evento',
            'eventi',
            'concerto',
            'concerti',
            'partite a san siro',
            'san siro',
            'fashion week',
            'salone del mobile',
            'marathon',
            'maratona',
            'chiude',
            'chiusura',
            'metro',
            'metropolitana',
            'm1',
            'm2',
            'm3',
            'm4',
            'm5',
            'trenord',
            'stazione',
            'circolazione',
            'viabilità',
            'traffico',
            'lavori',
            'cantieri',
            'cambiamenti programmati',
        ];

        foreach ($importantKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function detectType(string $title, string $source): string
    {
        $text = mb_strtolower($title);

        return match (true) {
            str_contains($text, 'sciopero') => 'strike',
            str_contains($text, 'scioperi') => 'strike',
            str_contains($text, 'agitazione') => 'strike',
            str_contains($text, 'concerto') => 'event',
            str_contains($text, 'san siro') => 'event',
            str_contains($text, 'manifestazione') => 'event',
            str_contains($text, 'maratona') => 'event',
            str_contains($text, 'marathon') => 'event',
            str_contains($text, 'lavori') => 'roadwork',
            str_contains($text, 'cantieri') => 'roadwork',
            str_contains($text, 'chiusura') => 'roadwork',
            str_contains($text, 'chiude') => 'roadwork',
            $source === 'trenord' => 'transport',
            default => 'transport',
        };
    }

    protected function detectRisk(string $title, string $source): string
    {
        $text = mb_strtolower($title);

        if (
            str_contains($text, 'sciopero') ||
            str_contains($text, 'scioperi') ||
            str_contains($text, 'agitazione') ||
            str_contains($text, 'san siro') ||
            str_contains($text, 'manifestazione') ||
            str_contains($text, 'chiusura') ||
            str_contains($text, 'chiude')
        ) {
            return 'high';
        }

        if (
            $source === 'trenord' ||
            str_contains($text, 'modifiche') ||
            str_contains($text, 'cambiamenti') ||
            str_contains($text, 'lavori') ||
            str_contains($text, 'cantieri') ||
            str_contains($text, 'metro') ||
            str_contains($text, 'trenord') ||
            str_contains($text, 'circolazione')
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
            'crescenzago' => 'Crescenzago',
            'gobba' => 'Cascina Gobba',
            'gessate' => 'Gessate',
            'assago' => 'Assago',
            'rho' => 'Rho',
            'monza' => 'Monza',
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
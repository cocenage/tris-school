<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobilityAlertSyncService
{
    public function splitTelegramStatusEvents(string $title): array
    {
        $clean = $this->cleanTitle($title);

        preg_match_all(
            '/(?:linea\s*)?(M[1-5])\s*(REGOLARE|PARZ\.\s*SOSPESA|PARZIALMENTE\s*SOSPESA|CHIUSA|SOSPESA)/iu',
            $clean,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->map(function (array $match): ?array {
                $line = mb_strtoupper($match[1]);
                $status = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($match[2])));

                if ($status === 'REGOLARE') {
                    return null;
                }

                $partial = str_contains($status, 'PARZ');

                return [
                    'line' => $line,
                    'status' => $status,
                    'type' => $partial ? 'partial_closure' : 'closure',
                    'risk' => $partial ? 'medium' : 'high',
                    'title' => $line . ' ' . $status,
                    'description' => $line . ' ' . $status,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function isTelegramStatusMessage(string $title): bool
    {
        return preg_match(
            '/(?:linea\s*)?M[1-5]\s*(?:REGOLARE|PARZ\.\s*SOSPESA|PARZIALMENTE\s*SOSPESA|CHIUSA|SOSPESA)/iu',
            $this->cleanTitle($title),
        ) === 1;
    }

    /**
     * Read-only suppression of legacy source rows when a normalized event is
     * already present in the same read set.
     */
    public function filterRepresentedRawAlerts(Collection $alerts): Collection
    {
        $normalizedIdentities = $alerts
            ->filter(fn (MobilityAlert $alert): bool => $this->isNormalizedRepresentation($alert))
            ->flatMap(fn (MobilityAlert $alert): array => $this->representationIdentities($alert))
            ->unique()
            ->values();

        if ($normalizedIdentities->isEmpty()) {
            return $alerts->values();
        }

        return $alerts
            ->reject(function (MobilityAlert $alert) use ($normalizedIdentities): bool {
                if ($this->isNormalizedRepresentation($alert) || $this->isIndependentEvent($alert)) {
                    return false;
                }

                $identities = $this->representationIdentities($alert);

                return $identities !== []
                    && collect($identities)->intersect($normalizedIdentities)->isNotEmpty();
            })
            ->values();
    }

    public function isNormalizedRepresentation(MobilityAlert $alert): bool
    {
        $events = $this->splitTelegramStatusEvents($alert->title);

        if (count($events) !== 1) {
            return false;
        }

        $cleanTitle = $this->normalisedKey($alert->title);
        $eventTitle = $this->normalisedKey($events[0]['title']);

        return $cleanTitle === $eventTitle;
    }

    protected function representationIdentities(MobilityAlert $alert): array
    {
        $text = $this->cleanTitle($alert->title . ' ' . ($alert->description ?? ''));
        $events = $this->splitTelegramStatusEvents($alert->title);

        if ($events !== []) {
            return collect($events)
                ->map(fn (array $event): string => $this->lineIdentity(
                    $event['line'],
                    $event['type'],
                    $alert->starts_at?->toDateString(),
                ))
                ->all();
        }

        preg_match('/\b(M[1-5])\b/i', $text, $lineMatch);

        if (! isset($lineMatch[1]) || ! $this->isRepresentableRawText($text)) {
            return [];
        }

        $line = mb_strtoupper($lineMatch[1]);
        $type = $this->inferRawEventType($text, $alert->type);

        return [$this->lineIdentity($line, $type, $alert->starts_at?->toDateString())];
    }

    protected function lineIdentity(string $line, string $type, ?string $date): string
    {
        return mb_strtoupper($line) . '|' . $type . '|' . ($date ?: '');
    }

    protected function inferRawEventType(string $text, ?string $type): string
    {
        $lower = mb_strtolower($text);

        if (str_contains($lower, 'bus')
            || str_contains($lower, 'crescenzago')
            || str_contains($lower, 'gobba')
            || str_contains($lower, 'cologno')) {
            return 'partial_closure';
        }

        if (str_contains($lower, 'chius') || str_contains($lower, 'sospes')) {
            return 'closure';
        }

        return $type ?: 'info';
    }

    protected function isRepresentableRawText(string $text): bool
    {
        $lower = mb_strtolower($text);

        return str_contains($lower, 'chius')
            || str_contains($lower, 'sospes')
            || str_contains($lower, 'bus')
            || str_contains($lower, 'crescenzago')
            || str_contains($lower, 'gobba')
            || str_contains($lower, 'cologno')
            || str_contains($lower, 'parz');
    }

    protected function isIndependentEvent(MobilityAlert $alert): bool
    {
        $text = mb_strtolower($alert->title . ' ' . ($alert->description ?? ''));

        foreach (['concert', 'concerto', 'san siro', 'assago', 'partita', 'maraton', 'evento'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function normalisedKey(?string $value): string
    {
        $value = mb_strtolower($this->cleanTitle((string) $value));

        return trim((string) preg_replace('/[\p{P}\p{S}\s]+/u', ' ', $value));
    }

    public function canonicalFingerprint(
        string $source,
        string $title,
        ?string $description,
        ?string $type,
        ?string $date,
    ): string {
        $text = mb_strtolower($this->cleanTitle($title . ' ' . ($description ?? '')));
        preg_match('/\b(M[1-5])\b/i', $text, $lineMatch);
        $line = mb_strtoupper($lineMatch[1] ?? '');

        $eventType = $type ?: $this->detectType($text, $source);
        $stations = collect([
            'gobba',
            'cologno nord',
            'crescenzago',
            'garibaldi',
            'porta genova',
            'cadorna',
            'centrale',
        ])->filter(fn (string $station): bool => str_contains($text, $station));

        $m2Cluster = $stations->intersect(['gobba', 'cologno nord', 'crescenzago'])->isNotEmpty();

        if ($line === '' && $m2Cluster) {
            $line = 'M2';
        }

        if ($line === 'M2' && $m2Cluster) {
            $segment = 'm2-crescenzago-gobba-cologno-nord';
            $eventType = in_array($eventType, ['closure', 'works', 'disruption'], true)
                ? 'partial_closure'
                : $eventType;
        } else {
            $segment = $stations->sort()->implode('|');
        }

        if ($segment === '') {
            $segment = preg_replace('/[^a-z0-9]+/iu', ' ', $text);
            $segment = trim((string) preg_replace('/\s+/u', ' ', $segment));
        }

        $operator = str_contains($text, 'm1')
            || str_contains($text, 'm2')
            || str_contains($text, 'm3')
            || str_contains($text, 'm4')
            || str_contains($text, 'm5')
            || str_contains(mb_strtolower($source), 'telegram')
            ? 'atm'
            : mb_strtolower($source);

        return sha1(implode('|', [
            $operator,
            $line,
            $eventType,
            $segment,
            $date ?: '',
        ]));
    }

    public function sync(): int
    {
        return $this->syncMitStrikes()
            + $this->syncAtm()
            + $this->syncTrenord()
            + $this->syncUndergroundStatus();
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

        foreach ([
            'https://www.trenord.it/news/',
            'https://www.trenord.it/assistenza/informazioni-utili/in-caso-di-sciopero/',
            'https://www.trenord.it/',
        ] as $url) {
            $created += $this->syncGenericPage(
                source: 'trenord',
                url: $url
            );
        }

        return $created;
    }

    protected function syncUndergroundStatus(): int
    {
        $url = 'https://t.me/s/undergroundstatus';

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
            Log::warning('Mobility telegram source failed', [
                'source' => 'telegram_undergroundstatus',
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('Mobility telegram source bad status', [
                'source' => 'telegram_undergroundstatus',
                'url' => $url,
                'status' => $response->status(),
            ]);

            return 0;
        }

        preg_match_all(
            '/<div class="tgme_widget_message_text js-message_text"[^>]*>(.*?)<\/div>/is',
            $response->body(),
            $matches
        );

        $created = 0;

        foreach ($matches[1] ?? [] as $rawText) {
            $text = str_replace(['<br/>', '<br>', '<br />'], "\n", $rawText);
            $title = $this->cleanTitle(strip_tags($text));

            if (! $this->looksLikeUndergroundAlert($title)) {
                continue;
            }

            $lineEvents = $this->splitTelegramStatusEvents($title);

            if ($lineEvents === [] && $this->isTelegramStatusMessage($title)) {
                continue;
            }

            $events = $lineEvents ?: [[
                'title' => $title,
                'description' => $title,
                'type' => $this->detectType($title, 'telegram'),
                'risk' => $this->detectRisk($title, 'telegram'),
            ]];

            foreach ($events as $event) {
                $eventTitle = $event['title'];
                $eventDescription = $event['description'];
                $eventDate = now()->startOfDay();
                $hash = $this->canonicalFingerprint(
                    'telegram',
                    $eventTitle,
                    $eventDescription,
                    $event['type'],
                    $eventDate->toDateString(),
                );

                $alert = MobilityAlert::firstOrCreate(
                    ['external_hash' => $hash],
                    [
                        'source' => 'telegram',
                        'title' => Str::limit($eventTitle, 250, ''),
                        'description' => $eventDescription,
                        'url' => $url,
                        'type' => $event['type'],
                        'risk' => $event['risk'],
                        'district' => $event['line'] ?? $this->detectDistrict($title),
                        'starts_at' => $eventDate,
                        'ends_at' => null,
                    ]
                );

                if ($alert->wasRecentlyCreated) {
                    $created++;
                } elseif (mb_strlen($eventDescription) > mb_strlen((string) $alert->description)) {
                    $alert->forceFill([
                        'title' => Str::limit($eventTitle, 250, ''),
                        'description' => $eventDescription,
                        'url' => $url,
                        'type' => $event['type'],
                        'risk' => $event['risk'],
                        'district' => $event['line'] ?? $this->detectDistrict($title),
                    ])->saveQuietly();
                }
            }
        }

        Log::info('Mobility telegram source finished', [
            'source' => 'telegram_undergroundstatus',
            'created' => $created,
        ]);

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

        $items = $this->extractLinks($url, $response->body());

        $created = 0;

        foreach ($items as $item) {
            $title = $this->cleanTitle($item['title']);

            if (! $this->looksUseful($title, $source)) {
                continue;
            }

            $eventDate = $this->extractDate($title) ?? now()->startOfDay();

            $hash = $this->fingerprint($source, $title, $eventDate->toDateString());

            $alert = MobilityAlert::firstOrCreate(
                ['external_hash' => $hash],
                [
                    'source' => $source,
                    'title' => Str::limit($title, 250, ''),
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
            } elseif (mb_strlen($title) > mb_strlen((string) $alert->description)) {
                $alert->forceFill([
                    'title' => Str::limit($title, 250, ''),
                    'description' => $title,
                    'url' => $item['url'],
                    'type' => $forcedType ?? $this->detectType($title, $source),
                    'risk' => $forcedRisk ?? $this->detectRisk($title, $source),
                    'district' => $this->detectDistrict($title),
                ])->saveQuietly();
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

    protected function looksUseful(string $title, string $source): bool
    {
        $text = mb_strtolower($title);

        foreach ([
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
        ] as $keyword) {
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

        foreach ([
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
        ] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeUndergroundAlert(string $title): bool
    {
        $text = mb_strtolower($title);

        foreach ([
            'm1',
            'm2',
            'm3',
            'm4',
            'm5',
            'metro',
            'metropolitana',
            'sciopero',
            'scioperi',
            'chiusa',
            'chiuso',
            'chiude',
            'interrotta',
            'interrotto',
            'sospesa',
            'sospeso',
            'ritardi',
            'rallentamenti',
            'servizio',
            'circolazione',
            'atm',
        ] as $keyword) {
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
            str_contains($text, 'sciopero'), str_contains($text, 'scioperi'), str_contains($text, 'agitazione') => 'strike',
            str_contains($text, 'chiusura totale'), str_contains($text, 'linea chiusa'), str_contains($text, 'servizio sospeso') => 'closure',
            str_contains($text, 'chiusura parziale'), str_contains($text, 'servizio parziale'), str_contains($text, 'autobus sostitutivi') => 'partial_closure',
            str_contains($text, 'ritardi'), str_contains($text, 'rallentamenti'), str_contains($text, 'interrotta'), str_contains($text, 'interrotto') => 'disruption',
            str_contains($text, 'lavori'), str_contains($text, 'cantieri'), str_contains($text, 'chiusura'), str_contains($text, 'chiude') => 'works',
            str_contains($text, 'concerto'), str_contains($text, 'san siro'), str_contains($text, 'manifestazione'), str_contains($text, 'maratona'), str_contains($text, 'marathon') => 'info',
            default => 'info',
        };
    }

    protected function detectRisk(string $title, string $source): string
    {
        $text = mb_strtolower($title);

        if (str_contains($text, 'regolare') || str_contains($text, 'servizio normale') || str_contains($text, 'nessun problema')) {
            return 'low';
        }

        if (
            str_contains($text, 'sciopero') ||
            str_contains($text, 'scioperi') ||
            str_contains($text, 'agitazione') ||
            str_contains($text, 'interrotta') ||
            str_contains($text, 'interrotto') ||
            str_contains($text, 'sospesa') ||
            str_contains($text, 'sospeso') ||
            str_contains($text, 'chiusa') ||
            str_contains($text, 'chiuso') ||
            str_contains($text, 'chiusura') ||
            str_contains($text, 'chiude') ||
            str_contains($text, 'san siro') ||
            str_contains($text, 'manifestazione') ||
            str_contains($text, 'servizio sospeso') ||
            str_contains($text, 'chiusura totale')
        ) {
            return 'high';
        }

        if (
            $source === 'trenord' ||
            str_contains($text, 'ritardi') ||
            str_contains($text, 'rallentamenti') ||
            str_contains($text, 'modifiche') ||
            str_contains($text, 'cambiamenti') ||
            str_contains($text, 'lavori') ||
            str_contains($text, 'cantieri') ||
            str_contains($text, 'metro') ||
            str_contains($text, 'trenord') ||
            str_contains($text, 'circolazione') ||
            str_contains($text, 'autobus sostitutivi') ||
            str_contains($text, 'chiusura parziale')
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
            'm1' => 'M1',
            'm2' => 'M2',
            'm3' => 'M3',
            'm4' => 'M4',
            'm5' => 'M5',
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

    protected function cleanTitle(string $title): string
    {
        $title = html_entity_decode($title);
        $title = strip_tags($title);
        $title = preg_replace('/https?:\/\/\S+/iu', '', $title);
        $title = preg_replace('/milanmetrost[a-z.\s]*[èe] anche su chatgpt/iu', '', $title);
        $title = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    protected function fingerprint(string $source, string $title, string $date): string
    {
        return $this->canonicalFingerprint($source, $title, null, null, $date);
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
}

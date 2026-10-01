<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MitStrikeSource
{
    public function fetch(): array
    {
        $response = Http::connectTimeout(5)->timeout(20)
            ->get(config('mobility.strike_source_url'))->throw();

        return $this->normalize($response->body());
    }

    public function normalize(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $items = [];
        $recognized = false;
        $invalid = 0;
        foreach ($xpath->query('//table') as $table) {
            $headers = [];
            foreach ($xpath->query('.//tr', $table) as $row) {
                $cells = iterator_to_array($xpath->query('./th|./td', $row));
                $values = array_map(fn ($cell): string => $this->clean($cell->textContent), $cells);
                if (in_array('Inizio', $values, true) && in_array('Categoria', $values, true)) {
                    $headers = $values;
                    $recognized = true;
                    continue;
                }
                if ($headers === [] || count($values) !== count($headers)) {
                    continue;
                }
                $fields = array_combine($headers, $values);
                try {
                    $start = $this->date($fields['Inizio']);
                    $end = $this->date($fields['Fine']);
                    if (! filled($fields['Categoria']) || $end->lt($start)) {
                        throw new RuntimeException('Invalid strike record.');
                    }
                    $item = [
                        'starts_at' => $start->toDateString(),
                        'ends_at' => $end->toDateString(),
                        'unions' => $fields['Sindacati'] ?? '',
                        'sector' => $fields['Settore'] ?? $fields['Settore*'] ?? '',
                        'operator' => $fields['Categoria'],
                        'duration' => $fields['Modalità'] ?? '',
                        'scope' => $fields['Rilevanza'] ?? '',
                        'notes' => $fields['Note'] ?? '',
                        'proclaimed_at' => $fields['Data proclamazione'] ?? '',
                        'region' => $fields['Regione'] ?? '',
                        'province' => $fields['Provincia'] ?? '',
                    ];
                    $item['status'] = preg_match('/\b(revocat[oa]|annullat[oa]|cancellat[oa])\b/iu',
                        ($fields['Stato'] ?? '').' '.$item['notes']) ? 'cancelled' : 'scheduled';
                    $sourceId = $fields['ID'] ?? '';
                    // Public MIT table has no ID: proclamation + parties + category
                    // remain stable when occurrence dates or duration are amended.
                    $unions = preg_split('/[\/;+]+/u', $this->key($item['unions']));
                    sort($unions);
                    $identity = $sourceId ?: implode('|', [$item['proclaimed_at'], implode('/', $unions), $item['sector'], $item['operator']]);
                    $item['identity'] = hash('sha256', 'mit|'.$this->key($identity));
                    $canonical = array_map($this->key(...), $item);
                    $canonical['unions'] = implode('/', $unions);
                    $item['version'] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                    $items[$item['identity']] = $item;
                } catch (\Throwable) {
                    $invalid++;
                }
            }
        }
        if (! $recognized) {
            throw new RuntimeException('MIT registry table was not recognized; no alerts were changed.');
        }

        return ['items' => array_values($items), 'invalid' => $invalid];
    }

    public function relevant(array $item): bool
    {
        if (! in_array($this->key($item['sector']), config('mobility.sectors'), true)) {
            return false;
        }
        // A national general strike may explicitly exclude all relevant transport.
        if (preg_match('/esclus[io].*ferroviario.*trasporto pubblico locale/iu', $item['notes'])) {
            return false;
        }
        if ($this->key($item['scope']) === 'nazionale') {
            return true;
        }
        foreach (['region' => 'regions', 'province' => 'provinces'] as $field => $setting) {
            if (in_array($this->key($item[$field]), config('mobility.'.$setting), true)) {
                return true;
            }
        }
        // Text fallback only when structured geographical metadata is absent.
        if ($item['region'] === '' && $item['province'] === '') {
            foreach (config('mobility.operators') as $operator) {
                if (str_contains($this->key($item['operator']), $operator)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!d/m/Y', $value, 'Europe/Rome');
        if (! $date || $date->format('d/m/Y') !== $value) {
            throw new RuntimeException('Invalid MIT date.');
        }

        return $date;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)));
    }

    private function key(string $value): string
    {
        return mb_strtolower($this->clean($value));
    }
}

<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RfiStrikeSource
{
    private const FEED = 'https://www.rfi.it/content/rfi/it/news-e-media/infomobilita.rss.notices.xml';

    public function __construct(private MobilityStrikeAnnouncementParser $announcements) {}

    public function fetch(): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->get(self::FEED);
            if (! $response->successful()) {
                return ['events' => [], 'issue' => 'http_'.$response->status()];
            }
        } catch (\Throwable) {
            return ['events' => [], 'issue' => 'request_failed'];
        }

        $feed = new DOMDocument;
        if (trim($response->body()) === '' || ! $feed->loadXML($response->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return ['events' => [], 'issue' => 'unrecognized_feed'];
        }
        $xml = new DOMXPath($feed);
        if ($xml->query('/rss/channel')->length !== 1) {
            return ['events' => [], 'issue' => 'unrecognized_feed'];
        }

        $events = [];
        $checked = [];
        $issue = null;
        foreach ($xml->query('/rss/channel/item') as $item) {
            $title = Str::squish($xml->evaluate('string(title)', $item));
            $url = trim($xml->evaluate('string(link)', $item));
            // RSS links use /content/rfi/ while the public article uses /it/.
            // Normalize before checking URLs and fetching the canonical article.
            $url = (string) preg_replace(
                '~^https://www\\.rfi\\.it/content/rfi/(?=it/news-e-media/infomobilita/avvisi/)~',
                'https://www.rfi.it/',
                $url,
            );
            if (preg_match('/\b(?:scioper\w*|agitazion\w*)\b/iu', $title) !== 1 || isset($checked[$url])) {
                continue;
            }
            if (! preg_match('~^https://www\.rfi\.it/it/news-e-media/infomobilita/avvisi/(20\d{2})/(\d{2})/(\d{2})/[^/?#]+\.html$~D', $url, $date)) {
                $issue = 'unrecognized_notice_url';

                continue;
            }
            if (count($checked) === 8) {
                $issue = 'notice_limit_reached';

                break;
            }
            $checked[$url] = true;

            try {
                $published = trim($xml->evaluate('string(pubDate)', $item));
                $publicationDate = CarbonImmutable::parse($published !== '' ? $published : "$date[1]-$date[2]-$date[3]", 'Europe/Rome')
                    ->setTimezone('Europe/Rome')->toDateString();
                $detail = Http::connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false])->get($url)->throw();
                if (! $detail->successful()) {
                    $issue = 'notice_request_failed';

                    continue;
                }
                $event = $this->notice($title, $url, $detail->body(), $publicationDate);
                if ($event !== null) {
                    $events[] = $event;
                }
            } catch (\Throwable) {
                $issue = 'notice_request_or_parse_failed';
            }
        }

        return ['events' => $events, 'issue' => $issue];
    }

    private function notice(string $title, string $url, string $html, string $publicationDate): ?array
    {
        $body = Str::squish(html_entity_decode(
            strip_tags(preg_replace('~</(?:p|div|li|h[1-6]|strong|span)>|<br\s*/?>~i', ' ', $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
        $date = '\d{1,2} [a-z]+ 20\d{2}';
        if (! preg_match('/\bIn vigore\s*:\s*('.$date.')(?:\s*[-–—]\s*('.$date.'))?/iu', $body, $period)
            || ! preg_match('/\bImpresa\s*:\s*(.{0,250}?)(?=\b(?:Regione|Causa Modifica|Servizio)\s*:|\b(?:Dalle|Lo sciopero|In caso)\b|[.!?]|$)/iu', $body, $operators)
            || ! preg_match('/\bTrenord\b/iu', $operators[1])
            || ! preg_match('/\bnazionale\b/iu', $title)) {
            return null;
        }

        $start = $this->announcements->normalize('Sciopero '.$period[1])['starts_at'];
        $end = isset($period[2]) ? $this->announcements->normalize('Sciopero '.$period[2])['starts_at'] : $start;
        if ($start === null || $end === null || $end < $start || $end < now('Europe/Rome')->toDateString()) {
            return null;
        }

        $time = '(?:[01]?\d|2[0-3]):[0-5]\d';
        $statement = Str::after($body, $operators[0]);
        $statement = explode('.', trim($statement), 2)[0];
        $status = $this->announcements->classifyStatus($title, $statement, hasEventDate: true);
        $duration = preg_match('/\bdalle ore ('.$time.')\b[^.!?]{0,100}?\balle ore ('.$time.')\b/iu', $statement, $hours)
            ? $hours[1].'–'.$hours[2] : null;

        return [
            'source' => 'trenord', 'url' => $url, 'title' => $title,
            'starts_at' => $start, 'ends_at' => $end, 'notice_date' => $start,
            'district' => 'Lombardia', 'risk' => $status === 'scheduled' ? 'high' : 'low',
            'description' => $statement,
            'metadata' => [
                'notice_provider' => 'rfi', 'status' => $status, 'operator' => 'Trenord', 'sector' => 'Ferroviario',
                'region' => 'Lombardia', 'scope' => 'Nazionale', 'publication_date' => $publicationDate, 'duration' => $duration,
            ],
        ];
    }
}

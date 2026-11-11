<?php

namespace App\Services\Mobility;

use Carbon\CarbonImmutable;

class MobilityStrikeAnnouncementParser
{
    private const MONTHS = [
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

    /**
     * Normalize an official notice. Publication metadata is deliberately not
     * used as the date of the strike.
     *
     * @return array{status: string, starts_at: ?string, ends_at: ?string}
     */
    public function normalize(string $title, string $body = '', ?string $publicationDate = null): array
    {
        $title = $this->clean($title);
        $body = $this->clean($body);
        $dates = $this->eventDates($title, $body, $publicationDate);

        return [
            'status' => $this->classifyStatus($title, $body, $dates['starts_at'] !== null),
            ...$dates,
        ];
    }

    public function classifyStatus(string $title, string $body = '', bool $hasEventDate = false): string
    {
        $title = $this->clean($title);
        $body = $this->clean($body);

        return $this->status(mb_strtolower($title.' '.$body), $title, $hasEventDate);
    }

    private function status(string $text, string $title, bool $hasEventDate): string
    {
        if (! str_contains($text, 'scioper') && ! str_contains($text, 'agitazion')) {
            return 'unknown';
        }

        if (preg_match('/\b(?:revocat\w*|annullat\w*|cancellat\w*)\b/u', $text) === 1) {
            return 'cancelled';
        }

        if (preg_match('/\b(?:rimandat\w*|rinviat\w*|differit\w*|posticipat\w*)\b/u', $text) === 1) {
            return 'postponed';
        }

        if (preg_match('/\b(?:aggiornament\w*|modificat\w*|variazion\w*|cambiament\w*)\b/u', $text) === 1) {
            return 'updated';
        }

        if (preg_match('/\b(?:confermat\w*|indett\w*|proclamat\w*|in programma|previst\w*)\b/u', $text) === 1) {
            return 'scheduled';
        }

        if ($hasEventDate && preg_match('/^\s*(?:sciopero|agitazion\w*)\b/iu', $title) === 1) {
            return 'scheduled';
        }

        return 'unknown';
    }

    /** @return array{starts_at: ?string, ends_at: ?string} */
    private function eventDates(string $title, string $body, ?string $publicationDate): array
    {
        foreach ([$title, $body] as $text) {
            if (preg_match_all(
                '/(?<first>\d{1,2})(?:\s*[-–—]\s*(?<last>\d{1,2}))?\s+(?<month>'.implode('|', array_keys(self::MONTHS)).')(?:\s+(?<year>20\d{2}))?/iu',
                $text,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            ) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $dateOffset = $match[0][1];
                $context = mb_strtolower(substr($text, max(0, $dateOffset - 80), $dateOffset));
                if (! str_contains($context, 'scioper') && ! str_contains($context, 'agitazion')
                    && preg_match('/\b(?:lunedi|martedi|mercoledi|giovedi|venerdi|sabato|domenica)\b/u', $context) !== 1) {
                    continue;
                }

                $month = self::MONTHS[mb_strtolower($match['month'][0])] ?? null;
                $yearText = ($match['year'][0] ?? '') !== '' ? $match['year'][0] : null;
                $year = $yearText !== null
                    ? (int) $yearText
                    : ($publicationDate !== null ? CarbonImmutable::parse($publicationDate, 'Europe/Rome')->year : null);

                if ($year === null) {
                    continue;
                }

                $first = (int) $match['first'][0];
                $lastText = $match['last'][0] ?? '';
                $start = $this->date($year, $month, $first);
                $end = $this->date($year, $month, $lastText !== '' ? (int) $lastText : $first);

                if ($start !== null && $end !== null && $end >= $start) {
                    return ['starts_at' => $start, 'ends_at' => $end];
                }
            }
        }

        return ['starts_at' => null, 'ends_at' => null];
    }

    private function date(int $year, ?int $month, int $day): ?string
    {
        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'Europe/Rome')?->toDateString();
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}

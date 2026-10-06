<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;

class MobilityStrikesSummaryBuilder
{
    public function __construct(private MitStrikeSource $mit) {}

    public function build(string $date): array
    {
        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereDate('starts_at', $date)->orWhereDate('ends_at', '>=', $date);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (MobilityAlert $alert): bool => $this->relevant($alert, $date));

        $items = $alerts->map(fn (MobilityAlert $alert): array => $this->item($alert))
            ->sortBy(fn (array $item): int => $item['source'] === 'mit' ? 0 : 1)
            ->values();
        $mitByOperator = $items->filter(fn (array $item): bool => $item['source'] === 'mit')
            ->groupBy('operator_key');
        $seen = [];
        $strikes = [];

        foreach ($items as $item) {
            if ($item['source'] !== 'mit' && ($mitByOperator[$item['operator_key']] ?? collect())->count() === 1) {
                $item['duplicate_of'] = $mitByOperator[$item['operator_key']]->first()['identity'];
            }

            $key = $item['duplicate_of'] ?? $item['identity'];
            if (isset($seen[$key])) {
                $index = $seen[$key];
                $strikes[$index]['sources'][] = $item['source_label'];
                if ($strikes[$index]['duration'] === null && $item['duration'] !== null) {
                    $strikes[$index]['duration'] = $item['duration'];
                }

                continue;
            }

            $seen[$key] = count($strikes);
            $item['sources'] = [$item['source_label']];
            unset($item['duplicate_of']);
            $strikes[] = $item;
        }

        usort($strikes, fn (array $a, array $b): int => $a['cancelled'] <=> $b['cancelled']);

        return ['date' => $date, 'strikes' => $strikes];
    }

    private function relevant(MobilityAlert $alert, string $date): bool
    {
        $text = mb_strtolower(trim($alert->title.' '.($alert->description ?? '')));
        if ($alert->type !== 'strike' && ! preg_match('/scioper|забаст|\bstrike\b/iu', $text)) {
            return false;
        }

        $source = mb_strtolower((string) $alert->source);
        $metadata = $alert->strike_metadata;
        if ($source === 'mit' && is_array($metadata)) {
            if (! $this->mit->relevant($metadata)) {
                return false;
            }

            $transport = mb_strtolower(implode(' ', [
                $metadata['sector'] ?? '', $metadata['operator'] ?? '', $metadata['notes'] ?? '',
            ]));

            return preg_match('/ferroviar|trasporto pubblico locale|\batm\b|trenord/iu', $transport) === 1;
        }

        if (! in_array($source, ['atm', 'trenord', 'luceverde'], true) || ! $this->mentionsDate($text, $date)) {
            return false;
        }

        return $source !== 'luceverde'
            || preg_match('/milano|lombardia|\batm\b|trenord|ferroviar|trasporto pubblico/iu', $text) === 1;
    }

    private function mentionsDate(string $text, string $date): bool
    {
        [$year, $month, $day] = explode('-', $date);
        $day = (string) (int) $day;
        $monthNumber = (string) (int) $month;
        $numeric = '/(?<!\d)0?'.preg_quote($day, '/').'\s*[\/.]\s*0?'.preg_quote($monthNumber, '/')
            .'(?:\s*[\/.]\s*(\d{4}))?(?!\s*[\/.]\s*\d{4}|\d)/u';
        if (preg_match($numeric, $text, $match)) {
            return ! isset($match[1]) || $match[1] === $year;
        }

        $months = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        $named = '/(?<!\d)0?'.preg_quote($day, '/').'\s+'.($months[(int) $month] ?? 'missing')
            .'(?:\s+(\d{4}))?(?!\s+\d{4}|\d)/u';

        return preg_match($named, $text, $match) === 1 && (! isset($match[1]) || $match[1] === $year);
    }

    private function item(MobilityAlert $alert): array
    {
        $metadata = $alert->strike_metadata ?? [];
        $source = mb_strtolower((string) $alert->source);
        $rawOperator = (string) ($metadata['operator'] ?? '');
        $operatorText = mb_strtolower($rawOperator.' '.$alert->title);
        $operator = match (true) {
            $source === 'atm' || preg_match('/\batm\b/iu', $operatorText) === 1 => 'ATM Milano',
            $source === 'trenord' || str_contains($operatorText, 'trenord') => 'Trenord',
            default => trim($rawOperator) ?: trim($alert->title),
        };
        $operatorKey = mb_strtolower($operator);
        $description = trim(html_entity_decode(strip_tags((string) $alert->description)));
        $title = trim(html_entity_decode(strip_tags($alert->title)));
        $statusText = mb_strtolower($title.' '.$description.' '.($metadata['notes'] ?? ''));
        $cancelled = ($metadata['status'] ?? null) === 'cancelled'
            || preg_match('/revocat|annullat|cancellat|отменен|отменён/iu', $statusText) === 1;
        $duration = trim((string) ($metadata['duration'] ?? ''));
        if ($duration === '' && preg_match('/\b\d{1,2}:\d{2}\s*[-–—]\s*\d{1,2}:\d{2}\b/u', $title.' '.$description, $match)) {
            $duration = $match[0];
        }
        $notes = trim((string) ($metadata['notes'] ?? ''));
        $guarantee = preg_match('/fasce?\s+garantit|servizi?\s+minimi?\s+garantit/iu', $notes)
            ? $notes : null;
        $sector = mb_strtolower((string) ($metadata['sector'] ?? ''));
        $effect = match (true) {
            str_contains($sector, 'ferroviar') || $operator === 'Trenord' => 'Возможны перебои железнодорожного сообщения.',
            str_contains($sector, 'trasporto pubblico') || $operator === 'ATM Milano' => 'Возможны перебои в работе общественного транспорта.',
            default => null,
        };

        return [
            'identity' => $source === 'mit' ? 'mit:'.($metadata['identity'] ?? $alert->external_hash)
                : $operatorKey.'|'.mb_strtolower($title.' '.$description),
            'operator_key' => $operatorKey,
            'operator' => $operator,
            'source' => $source,
            'source_label' => match ($source) {
                'mit' => 'MIT', 'atm' => 'ATM Milano', 'trenord' => 'Trenord', 'luceverde' => 'Luceverde',
                default => $source,
            },
            'cancelled' => $cancelled,
            'updated' => ($metadata['notification_kind'] ?? null) === 'updated',
            'duration' => $duration !== '' ? $duration : null,
            'guarantee' => $guarantee,
            'effect' => $effect,
            'detail' => $source === 'mit' ? null : $title,
        ];
    }
}

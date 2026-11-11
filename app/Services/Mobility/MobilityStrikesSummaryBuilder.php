<?php

namespace App\Services\Mobility;

use App\Models\MobilityAlert;
use Carbon\CarbonImmutable;

class MobilityStrikesSummaryBuilder
{
    public function __construct(
        private MitStrikeSource $mit,
        private MobilityStrikeSourceStatus $sourceStatus,
    ) {}

    public function build(string $date, ?string $district = null, int $daysAhead = 0): array
    {
        $start = CarbonImmutable::parse($date, 'Europe/Rome')->startOfDay();
        $daysAhead = max(0, min(7, $daysAhead));
        $end = $start->addDays($daysAhead);
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $alerts = MobilityAlert::query()
            ->whereDate('starts_at', '<=', $endDate)
            ->where(function ($query) use ($startDate, $endDate): void {
                $query->whereDate('starts_at', '>=', $startDate)
                    ->whereDate('starts_at', '<=', $endDate)
                    ->orWhere(function ($active) use ($startDate): void {
                        $active->whereDate('starts_at', '<', $startDate)
                            ->whereDate('ends_at', '>=', $startDate);
                    });
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (MobilityAlert $alert): bool => $this->relevant(
                $alert,
                $alert->starts_at?->toDateString() ?? $startDate,
            ))
            ->filter(fn (MobilityAlert $alert): bool => $district === null || $this->appliesToDistrict($alert, $district));

        $items = $alerts->map(fn (MobilityAlert $alert): array => $this->item($alert))
            ->sortBy(fn (array $item): int => $item['source'] === 'mit' ? 0 : 1)
            ->values();
        $items = $items->reject(function (array $item) use ($items): bool {
            if ($item['source'] !== 'mit' || ! in_array($item['status'], ['scheduled', 'updated'], true)) {
                return false;
            }

            return $items->contains(fn (array $notice): bool => $notice['source'] !== 'mit'
                && in_array($notice['status'], ['postponed', 'cancelled'], true)
                && $this->sameOfficialEvent($item, $notice));
        })->values();
        $mitByOperator = $items->filter(fn (array $item): bool => $item['source'] === 'mit')
            ->groupBy('operator_key');
        $seen = [];
        $strikes = [];

        foreach ($items as $item) {
            $candidate = ($mitByOperator[$item['operator_key']] ?? collect())->first();
            if ($item['source'] !== 'mit' && $candidate !== null
                && ($mitByOperator[$item['operator_key']] ?? collect())->count() === 1
                && $this->sameReportedEvent($candidate, $item)) {
                $item['duplicate_of'] = $candidate['identity'];
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

        return [
            'date' => $startDate,
            'window_end' => $endDate,
            'days_ahead' => $daysAhead,
            'district' => $district,
            'strikes' => $strikes,
            'source_status' => $this->sourceStatus->forReport(),
        ];
    }

    private function appliesToDistrict(MobilityAlert $alert, string $district): bool
    {
        $district = mb_strtolower(trim($district));
        $milanDistricts = ['navigli', 'lodi', 'certosa', 'lambrate'];
        $metadata = $alert->strike_metadata ?? [];
        $source = mb_strtolower((string) $alert->source);
        $operator = mb_strtolower((string) ($metadata['operator'] ?? '').' '.$alert->title);
        $region = mb_strtolower(trim((string) ($metadata['region'] ?? '')));
        $province = mb_strtolower(trim((string) ($metadata['province'] ?? '')));
        $scope = mb_strtolower(trim((string) ($metadata['scope'] ?? '')));
        $sector = mb_strtolower(trim((string) ($metadata['sector'] ?? '')));

        $isAtm = $source === 'atm' || preg_match('/\batm\b|gruppo atm/iu', $operator) === 1;
        if ($isAtm) {
            return in_array($district, $milanDistricts, true);
        }

        if (str_contains($province, 'como')) {
            return $district === 'como';
        }

        if (str_contains($province, 'milano') || str_contains($province, 'milan')) {
            return in_array($district, $milanDistricts, true);
        }

        if (str_contains($scope, 'nazional')) {
            return true;
        }

        $location = mb_strtolower(trim((string) $alert->district));
        if (in_array($location, ['como', 'navigli', 'lodi', 'certosa', 'lambrate'], true)) {
            return $location === $district;
        }
        if (in_array($location, ['milano', 'milan'], true) || preg_match('/\bm[1-5]\b/iu', $location) === 1) {
            return in_array($district, $milanDistricts, true);
        }
        if (in_array($location, ['lombardia', 'italia'], true)) {
            return true;
        }

        $text = mb_strtolower($alert->title.' '.($alert->description ?? ''));
        if (preg_match('/\bcomo\b/iu', $text) === 1) {
            return $district === 'como';
        }
        if (preg_match('/\bmilano\b|\bmilan\b/iu', $text) === 1) {
            return in_array($district, $milanDistricts, true);
        }

        if ($region === 'lombardia') {
            return true;
        }

        if ($source === 'trenord' || str_contains($operator, 'trenord') || str_contains($sector, 'ferroviar')) {
            return true;
        }

        return false;
    }

    private function relevant(MobilityAlert $alert, string $date): bool
    {
        $text = mb_strtolower(trim($alert->title.' '.($alert->description ?? '')));
        if ($alert->type !== 'strike' && ! preg_match('/scioper|забаст|\bstrike\b/iu', $text)) {
            return false;
        }

        $source = mb_strtolower((string) $alert->source);
        $metadata = $alert->strike_metadata;
        $status = (string) data_get($metadata, 'status', 'unknown');
        if ($status === 'unknown') {
            return false;
        }
        if ($source === 'mit' && is_array($metadata)) {
            if (! $this->mit->relevant($metadata)) {
                return false;
            }

            $transport = mb_strtolower(implode(' ', [
                $metadata['sector'] ?? '', $metadata['operator'] ?? '', $metadata['notes'] ?? '',
            ]));

            return preg_match('/ferroviar|trasporto pubblico locale|\batm\b|trenord/iu', $transport) === 1;
        }

        if (! in_array($source, ['atm', 'trenord', 'luceverde'], true) || ! $this->mentionsDate($text, $date)
            && ! in_array($status, ['scheduled', 'updated', 'postponed', 'cancelled'], true)) {
            return false;
        }

        return $source !== 'luceverde'
            || preg_match('/milano|lombardia|\batm\b|trenord|ferroviar|trasporto pubblico/iu', $text) === 1;
    }

    private function sameOfficialEvent(array $mit, array $notice): bool
    {
        if (($mit['starts_at'] ?? null) !== ($notice['starts_at'] ?? null)) {
            return false;
        }

        $mitSector = mb_strtolower((string) ($mit['sector'] ?? ''));
        $noticeSource = mb_strtolower((string) ($notice['source'] ?? ''));
        if ($noticeSource === 'atm' && ! str_contains($mitSector, 'trasporto pubblico')) {
            return false;
        }
        if ($noticeSource === 'trenord' && ! str_contains($mitSector, 'ferroviar')) {
            return false;
        }

        $unions = array_filter(array_map('trim', preg_split('/[,;\/]+/u', (string) ($mit['unions'] ?? '')) ?: []));
        $noticeText = mb_strtolower((string) ($notice['detail'] ?? '').' '.(string) ($notice['notes'] ?? ''));
        foreach ($unions as $union) {
            if (mb_strlen($union) >= 3 && preg_match('/(?<![\pL\pN])'.preg_quote(mb_strtolower($union), '/').'(?![\pL\pN])/u', $noticeText) === 1) {
                return true;
            }
        }

        return false;
    }

    private function sameReportedEvent(array $mit, array $notice): bool
    {
        if (($mit['starts_at'] ?? null) !== ($notice['starts_at'] ?? null)
            || ! in_array($mit['status'], ['scheduled', 'updated'], true)
            || ! in_array($notice['status'], ['scheduled', 'updated'], true)) {
            return false;
        }

        $mitSector = mb_strtolower((string) ($mit['sector'] ?? ''));
        $noticeSector = mb_strtolower((string) ($notice['sector'] ?? ''));
        $mitMode = str_contains($mitSector, 'ferroviar') || $mit['source'] === 'trenord' ? 'rail' : 'local_transit';
        $noticeMode = str_contains($noticeSector, 'ferroviar') || $notice['source'] === 'trenord' ? 'rail' : 'local_transit';
        if ($mitMode !== $noticeMode) {
            return false;
        }

        $mitUnions = array_filter(array_map('trim', preg_split('/[,;\/]+/u', (string) ($mit['unions'] ?? '')) ?: []));
        $noticeUnions = array_filter(array_map('trim', preg_split('/[,;\/]+/u', (string) ($notice['unions'] ?? '')) ?: []));
        if ($mitUnions !== [] && $noticeUnions !== []) {
            return array_intersect(array_map('mb_strtolower', $mitUnions), array_map('mb_strtolower', $noticeUnions)) !== [];
        }

        return true;
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
        $status = (string) ($metadata['status'] ?? ($cancelled ? 'cancelled' : 'unknown'));
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
        $geography = collect([
            trim((string) ($metadata['scope'] ?? '')),
            trim((string) ($metadata['region'] ?? '')),
            trim((string) ($metadata['province'] ?? '')),
            trim((string) $alert->district),
        ])->filter()->unique()->implode(' · ');

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
            'status' => $status,
            'updated' => in_array($status, ['updated', 'scheduled'], true)
                && ($metadata['notification_kind'] ?? null) === 'updated',
            'postponed' => $status === 'postponed',
            'unions' => (string) ($metadata['unions'] ?? ''),
            'notes' => $notes,
            'duration' => $duration !== '' ? $duration : null,
            'guarantee' => $guarantee,
            'starts_at' => $alert->starts_at?->toDateString(),
            'ends_at' => $alert->ends_at?->toDateString(),
            'sector' => trim((string) ($metadata['sector'] ?? '')) ?: null,
            'geography' => $geography !== '' ? $geography : null,
            'effect' => $effect,
            'detail' => $source === 'mit' ? null : $title,
            'url' => filled($alert->url) ? $alert->url : ($source === 'mit' ? config('mobility.strike_source_url') : null),
        ];
    }
}

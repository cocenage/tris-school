<?php

namespace App\Services\Telegram;

class ScheduledControlResponseInterpreter
{
    public function __construct(private readonly ScheduledControlResponseClassifier $classifier) {}

    public function interpret(string $text, ?string $controlType = null): array
    {
        $classification = $this->classifier->classify($text)['classification'];
        $status = match ($classification) {
            'confirmed' => 'ok',
            'partial' => 'partial',
            'problem' => 'problem',
            default => 'unknown',
        };
        $district = null;
        if (preg_match('/\bвсе\b.{0,35}\bкроме\s+([\p{L}-]+)/iu', $text, $match) === 1) {
            $district = $this->districtLabel($match[1]);
            $status = 'partial';
        }

        $delayMinutes = preg_match('/(?:опозда\p{L}*|задерж\p{L}*|позже)\b.{0,30}?(?:(?:(?:примерно|около)\s+)?(?:на\s+)?(\d{1,3})\s*(?:минут\p{L}*|мин\b)|минут\p{L}*\s+на\s+(\d{1,3})\b)/iu', $text, $match) === 1
            ? (int) ($match[1] ?: $match[2])
            : null;
        if ($delayMinutes !== null || preg_match('/\b(?:нет|не\s+хват\p{L}*)\s+ключ/iu', $text) === 1) {
            $status = 'problem';
        }
        $districts = $this->districtResults($text, $controlType);
        if (collect($districts['districts'])->contains(fn (array $district): bool => $district['status'] === 'problem')) {
            $status = 'problem';
        }
        $reason = match (true) {
            preg_match('/ключ\p{L}*/iu', $text) === 1 => 'нет ключей',
            preg_match('/курьер\p{L}*.{0,30}не\s+приехал|курьер\p{L}*.{0,30}не\s+забрал/iu', $text) === 1 => 'курьер не выполнил доставку',
            preg_match('/опозда\p{L}*|задерж\p{L}*|позже/iu', $text) === 1 => 'задержка',
            default => null,
        };

        return [
            'status' => $status,
            'district' => $district,
            'districts' => $districts['districts'],
            'unassigned_details' => $districts['unassigned_details'],
            'delay_minutes' => $delayMinutes,
            'reason' => $reason,
            'issue' => $status === 'problem' || $status === 'partial' ? trim($text) : null,
        ];
    }

    /** @return array{districts: array<int, array<string, mixed>>, unassigned_details: array<int, string>} */
    private function districtResults(string $text, ?string $controlType): array
    {
        $lines = preg_split('/\R/u', str_replace("\0", '', $text)) ?: [];
        $districtLines = collect($this->classifier->districtLines($text))->keyBy('line_index');
        $districts = [];
        $unassigned = [];
        $currentDistrict = null;

        foreach ($lines as $index => $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '') {
                continue;
            }

            $districtLine = $districtLines->get($index);
            if ($districtLine !== null) {
                $label = $districtLine['district'];
                $status = $this->districtStatus($districtLine, $controlType);
                $detail = $this->districtDetail($districtLine['text']);
                $districts[$label] ??= [
                    'district' => $label,
                    'status' => $status,
                    'reason' => null,
                    'details' => [],
                    'no_payment' => $status === 'no_data' && $controlType === 'extra_payments_completed',
                ];
                if ($districts[$label]['status'] !== $status && $districts[$label]['status'] !== 'conflict') {
                    $districts[$label]['status'] = 'conflict';
                    $districts[$label]['no_payment'] = false;
                } elseif ($districts[$label]['status'] !== 'conflict') {
                    $districts[$label]['status'] = $status;
                    $districts[$label]['reason'] = $this->districtReason($detail);
                    $districts[$label]['no_payment'] = $status === 'no_data' && $controlType === 'extra_payments_completed';
                }
                if ($detail !== '') {
                    $districts[$label]['details'][] = $detail;
                }
                $currentDistrict = $label;

                continue;
            }

            if ($currentDistrict !== null
                && $districts[$currentDistrict]['status'] === 'problem'
                && ! preg_match('/^остальн\p{L}*/iu', $line)
                && $this->isOperationalDetail($line)
                && ! $this->mentionsAnotherDistrict($line, $currentDistrict)) {
                $districts[$currentDistrict]['details'][] = $line;
                $districts[$currentDistrict]['reason'] ??= $this->districtReason($line);

                continue;
            }

            if ($currentDistrict !== null
                && $controlType === 'extra_payments_completed'
                && $districts[$currentDistrict]['status'] === 'ok'
                && $this->isOperationalDetail($line)
                && ! $this->mentionsAnotherDistrict($line, $currentDistrict)) {
                $districts[$currentDistrict]['details'][] = $line;

                continue;
            }

            if ($this->isOperationalDetail($line)) {
                $unassigned[] = $line;
            }
        }

        return [
            'districts' => array_values(array_map(function (array $district): array {
                $district['details'] = array_values(array_unique(array_map(
                    fn (string $detail): string => trim((string) preg_replace('/\s+/u', ' ', $detail)),
                    $district['details'],
                )));

                return $district;
            }, $districts)),
            'unassigned_details' => array_values(array_unique(array_map(
                fn (string $detail): string => trim((string) preg_replace('/\s+/u', ' ', $detail)),
                $unassigned,
            ))),
        ];
    }

    private function districtStatus(array $line, ?string $controlType): string
    {
        $text = mb_strtolower(str_replace('ё', 'е', trim((string) $line['text'])));

        if ($controlType === 'extra_payments_completed'
            && preg_match('/^не\s+было(?:\s+(?:доплат|доплаты))?\s*[.!]?$|^не\s+платили\s*[.!]?$/u', $text) === 1) {
            return 'no_data';
        }
        if (preg_match('/\b(?:нет|не\s+(?:начал\p{L}*|заверш\p{L}*|закончил\p{L}*|успел\p{L}*|приехал\p{L}*|забрал\p{L}*|выплатил\p{L}*|проверил\p{L}*)|опозда\p{L}*|задерж\p{L}*|проблем\p{L}*)\b/u', $text) === 1
            || in_array($line['marker'], ['🔴', '❌'], true)) {
            return 'problem';
        }
        if (preg_match('/\b(?:да|начал\p{L}*|заверш\p{L}*|закончил\p{L}*|заканч\p{L}*|готов\p{L}*|проверен\p{L}*|приехал\p{L}*|получил\p{L}*|выплатил\p{L}*)\b/u', $text) === 1
            || in_array($line['marker'], ['🟢', '✅'], true)) {
            return 'ok';
        }

        return 'unknown';
    }

    private function districtDetail(string $text): string
    {
        $detail = trim($text);
        $detail = (string) preg_replace('/^(?:[-–—:,;]\s*)?(?:да|нет|не\s+было|не\s+начал\p{L}*|не\s+заверш\p{L}*|начал\p{L}*|заверш\p{L}*|закончил\p{L}*|заканч\p{L}*)\b\s*[,;:–—-]?\s*/iu', '', $detail);

        return trim((string) preg_replace('/\s+/u', ' ', $detail), " \t\n\r\0\x0B,;:—–-");
    }

    private function districtReason(string $text): ?string
    {
        return match (true) {
            preg_match('/опозда\p{L}*|задерж\p{L}*|позже|поезд/iu', $text) === 1 => 'задержка',
            preg_match('/курьер\p{L}*.{0,40}(?:не\s+приехал|не\s+забрал)|не\s+забрал\p{L}*/iu', $text) === 1 => 'курьер не выполнил доставку',
            preg_match('/не\s+начал\p{L}*/iu', $text) === 1 => 'уборка не началась',
            preg_match('/не\s+(?:заверш\p{L}*|закончил\p{L}*)/iu', $text) === 1 => 'не завершено вовремя',
            default => null,
        };
    }

    private function isOperationalDetail(string $text): bool
    {
        return preg_match('/\b(?:квартир\p{L}*|уборк\p{L}*|начал\p{L}*|заканч\p{L}*|заверш\p{L}*|закончил\p{L}*|опозда\p{L}*|задерж\p{L}*|курьер\p{L}*|выплат\p{L}*|ожидани\p{L}*|гост\p{L}*|выезд\p{L}*|забрал\p{L}*|приехал\p{L}*|\bда\b|\bнет\b)/iu', $text) === 1;
    }

    private function mentionsAnotherDistrict(string $text, string $currentDistrict): bool
    {
        foreach ($this->classifier->districtLabels() as $label) {
            if ($label !== $currentDistrict && preg_match('/\b'.preg_quote($label, '/').'\b/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function districtLabel(string $value): ?string
    {
        $normalized = mb_strtolower(str_replace('ё', 'е', $value));
        $known = ['como' => 'Como', 'комо' => 'Como'];
        foreach ($known as $alias => $label) {
            if ($normalized === $alias) {
                return $label;
            }
        }

        foreach (config('services.telegram.digest_districts', []) as $district) {
            $label = (string) ($district['label'] ?? '');
            if ($label !== '' && $normalized === mb_strtolower($label)) {
                return $label;
            }
        }

        return null;
    }
}

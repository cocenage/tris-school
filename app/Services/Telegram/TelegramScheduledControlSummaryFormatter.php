<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

class TelegramScheduledControlSummaryFormatter
{
    public function format(array $summary): string
    {
        $text = '🧹 TRIS — Контроль уборок · '.CarbonImmutable::parse($summary['date'])->format('d.m.Y')."\n";
        if ($summary['controls'] === []) {
            return $text."\n✅ Контрольных сообщений за день не было.\n";
        }

        $text .= "\n";

        foreach ($summary['controls'] as $control) {
            $text .= $this->controlLine($control);
        }
        if (($summary['totals']['response_messages'] ?? 0) === 0) {
            $text .= "\nℹ️ Ответы на контрольные сообщения за этот день не зафиксированы.\n";
        }

        return htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function controlLine(array $control): string
    {
        $type = $control['control_type'];
        $label = $control['label'];
        $districts = collect($control['district_results'] ?? []);

        if ($districts->isEmpty()) {
            $exception = collect($control['responses'])->first(fn (array $response): bool => in_array($response['status'], ['problem', 'partial'], true));

            return match ($control['status']) {
                'ok' => '✅ '.$label."\n",
                'problem' => '⚠️ '.$this->shortLabel($type, $label).': '.($exception ? $this->issueLine($exception) : 'есть отклонение')."\n",
                'unknown' => '⚪ '.$this->shortLabel($type, $label).": ответы неоднозначны\n",
                default => '⚪ '.$this->shortLabel($type, $label).": нет данных\n",
            };
        }

        $configured = collect(config('services.telegram.digest_districts', []))
            ->map(fn (mixed $district): string => trim((string) (is_array($district) ? ($district['label'] ?? '') : '')))
            ->filter()->unique()->values();
        $coveredNames = $districts->pluck('district')->unique();
        $missing = $configured->reject(fn (string $name): bool => $coveredNames->contains($name))->values();
        $problems = $districts->where('status', 'problem')->values();
        $conflicts = $districts->where('status', 'conflict')->values();
        $unknown = $districts->where('status', 'unknown')->values();
        $noPayments = $districts->filter(fn (array $district): bool => $district['no_payment'] ?? false)->values();

        if ($type === 'extra_payments_completed') {
            return $this->extraPaymentLine($districts, $missing, $problems, $conflicts, $unknown);
        }

        if ($conflicts->isNotEmpty()) {
            return '⚠️ '.$this->problemLabel($type, $label).': конфликтующие статусы — '.$this->districtNames($conflicts)."\n";
        }

        $line = '';
        if ($problems->isNotEmpty()) {
            $names = $this->districtNames($problems);
            $hasDelay = $problems->every(fn (array $district): bool => ($district['reason'] ?? null) === 'задержка');
            $line = match ($type) {
                'schedule_checked' => '⚠️ График и заметки: '.$names.' — есть отклонения',
                'first_cleanings_started' => '⚠️ Первые уборки: '.$names.($hasDelay ? ' с задержками' : ' — не все начались'),
                'first_cleanings_finishing' => '⚠️ Первые уборки: '.$names.' — не завершены вовремя',
                'second_cleanings_finishing' => '⚠️ Вторые уборки: '.$names.' — не завершены вовремя',
                'couriers_completed' => '⚠️ Курьеры: '.$names.' — есть незавершённые доставки',
                default => '⚠️ '.$label.': '.$names.' — есть отклонения',
            };
            $details = $problems->flatMap(fn (array $district): array => collect($district['details'] ?? [])
                ->map(fn (string $detail): string => $district['district'].' — '.$detail)->all())->unique()->values();
            if ($details->isNotEmpty()) {
                $line .= ' ('.implode('; ', $details->all()).')';
            }
            if ($missing->isNotEmpty()) {
                $line .= '; нет данных: '.$this->joinNames($missing->all());
            }

            return $line."\n";
        }

        if ($unknown->isNotEmpty()) {
            return '⚪ '.$label.': нет ясного ответа по '.$this->districtNames($unknown)."\n";
        }

        if ($missing->isNotEmpty()) {
            return '⚠️ '.$this->shortLabel($type, $label).': нет подтверждения по '.$this->joinNames($missing->all()).".\n";
        }

        if ($noPayments->isNotEmpty()) {
            return '💶 Доплаты: не было по всем районам'."\n";
        }

        $positive = match ($type) {
            'schedule_checked' => 'График и заметки проверены по всем районам',
            'first_cleanings_started' => 'Все первые уборки начались',
            'first_cleanings_finishing' => 'Первые уборки подходят к завершению по всем районам',
            'second_cleanings_finishing' => 'Вторые уборки подходят к завершению по всем районам',
            'couriers_completed' => 'Курьеры завершили доставки по всем районам',
            default => $label,
        };

        return '✅ '.$positive."\n";
    }

    private function extraPaymentLine($districts, $missing, $problems, $conflicts, $unknown): string
    {
        if ($conflicts->isNotEmpty()) {
            return '⚠️ Доплаты: конфликтующие статусы — '.$this->districtNames($conflicts)."\n";
        }

        if ($problems->isNotEmpty()) {
            $line = '⚠️ Доплаты: '.$this->districtNames($problems).' — требуется уточнение';
            $details = $problems->flatMap(fn (array $district): array => $district['details'] ?? [])->unique()->values();
            if ($details->isNotEmpty()) {
                $line .= ' ('.implode('; ', $details->all()).')';
            }

            return $line."\n";
        }

        $payments = $districts->filter(fn (array $district): bool => ($district['details'] ?? []) !== [])->values();
        if ($payments->isNotEmpty()) {
            $parts = $payments->map(fn (array $district): string => $district['district'].' — '.implode('; ', $district['details']))->all();
            $line = '💶 Доплаты: '.implode('; ', $parts);
            $noPayments = $districts->filter(fn (array $district): bool => $district['no_payment'] ?? false)->values();
            if ($noPayments->isNotEmpty()) {
                $line .= '; доплат не было: '.$this->districtNames($noPayments);
            }
            if ($missing->isNotEmpty()) {
                $line .= '; нет данных: '.$this->joinNames($missing->all());
            }

            return $line."\n";
        }

        if ($unknown->isNotEmpty()) {
            return '⚪ Доплаты: нет ясного ответа по '.$this->districtNames($unknown)."\n";
        }

        $noPayments = $districts->filter(fn (array $district): bool => $district['no_payment'] ?? false)->values();
        $phrase = $missing->isEmpty() && $noPayments->count() === $districts->count()
            ? 'не было по всем районам'
            : 'не было: '.$this->districtNames($noPayments);
        if ($missing->isNotEmpty()) {
            $phrase .= '; нет данных: '.$this->joinNames($missing->all());
        }

        return '💶 Доплаты: '.$phrase."\n";
    }

    private function districtNames($districts): string
    {
        return $this->joinNames($districts->pluck('district')->all());
    }

    private function joinNames(array $names): string
    {
        $names = array_values(array_unique(array_filter($names)));
        if (count($names) < 2) {
            return $names[0] ?? '';
        }

        return implode(', ', array_slice($names, 0, -1)).' и '.end($names);
    }

    private function issueLine(array $exception): string
    {
        if ($exception['district'] !== null && $exception['delay_minutes'] !== null) {
            return 'Задержка начала уборки — '.$exception['delay_minutes'].' мин.';
        }

        return $exception['text'];
    }

    private function problemLabel(string $controlType, string $label): string
    {
        return match ($controlType) {
            'first_cleanings_started', 'first_cleanings_finishing' => 'Первые уборки',
            'second_cleanings_finishing' => 'Вторые уборки',
            'couriers_completed' => 'Курьеры',
            'extra_payments_completed' => 'Доплаты',
            default => $label,
        };
    }

    private function shortLabel(string $controlType, string $label): string
    {
        return match ($controlType) {
            'schedule_checked' => 'График и заметки',
            'first_cleanings_started', 'first_cleanings_finishing' => 'Первые уборки',
            'second_cleanings_finishing' => 'Вторые уборки',
            'couriers_completed' => 'Курьеры',
            'extra_payments_completed' => 'Доплаты',
            default => $label,
        };
    }
}

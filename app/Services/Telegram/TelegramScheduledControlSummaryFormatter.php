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

        foreach ($summary['controls'] as $control) {
            $text .= $this->controlLine($control);
        }
        $shown = 0;
        $headingAdded = false;
        foreach ($summary['exceptions'] as $exception) {
            $section = $headingAdded ? '' : "\nОтклонения\n";
            $where = $exception['district'] ?? $exception['apartment'] ?? null;
            $controlLabel = ScheduledControlTypes::LABELS[$exception['control_type']] ?? $exception['control_type'];
            $section .= '• '.($where ? $where.' — ' : '').$this->issueLine($exception)."\n";
            if ($exception['reason']) {
                $section .= '  Причина: '.$exception['reason']."\n";
            }
            if ($exception['delay_minutes'] !== null) {
                $section .= '  Задержка: '.$exception['delay_minutes'].' мин'."\n";
            }
            if ($exception['resolved_later'] ?? false) {
                $section .= '  ✅ Позже подтверждено завершение на контроле «'.$controlLabel.'».'."\n";
            } else {
                $section .= '  ⚠️ На последнем связанном контроле отклонение не закрыто.'."\n";
            }
            $author = filled($exception['author_name'] ?? null) ? $exception['author_name'].' · ' : '';
            $section .= '  Источник: '.$author.CarbonImmutable::parse($exception['responded_at'])->format('H:i')
                .' · сообщение '.$exception['source_message_id']."\n";
            if ($shown >= 12 || mb_strlen($text.$section) > 3400) {
                break;
            }
            $text .= $section;
            $headingAdded = true;
            $shown++;
        }
        if (($summary['totals']['response_messages'] ?? 0) === 0) {
            $text .= "\nℹ️ Ответы на контрольные сообщения за этот день не зафиксированы.\n";
        } elseif ($summary['no_response_available'] === false
            && ! collect($summary['controls'])->contains(fn (array $control): bool => ($control['district_results'] ?? []) !== [])) {
            $text .= "\nℹ️ Список ожидаемых участников не настроен; отсутствие ответа не рассчитывается.\n";
        }

        return htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function controlLine(array $control): string
    {
        $type = $control['control_type'];
        $label = $control['label'];
        $districts = collect($control['district_results'] ?? []);

        if ($districts->isEmpty()) {
            $exceptionCount = collect($control['responses'])->whereIn('status', ['problem', 'partial'])->count();

            return match ($control['status']) {
                'ok' => '✅ '.$label."\n",
                'problem' => '⚠️ '.$this->problemLabel($type, $label).' — '.$exceptionCount.' '.$this->exceptionNoun($exceptionCount)."\n",
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
        $knownOk = $districts->where('status', 'ok')->values();
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
            $confirmed = $knownOk->isNotEmpty() ? ' подтверждены: '.$this->districtNames($knownOk) : '';

            return '⚪ '.$label.': нет данных по '.$this->joinNames($missing->all()).$confirmed."\n";
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

    private function exceptionNoun(int $count): string
    {
        $lastTwo = $count % 100;
        $last = $count % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return 'отклонений';
        }

        return match ($last) {
            1 => 'отклонение',
            2, 3, 4 => 'отклонения',
            default => 'отклонений',
        };
    }
}

<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

class TelegramScheduledControlSummaryFormatter
{
    public function format(array $summary): string
    {
        $text = '📊 TRIS — контроль дня · '.CarbonImmutable::parse($summary['date'])->format('d.m.Y')."\n";
        if ($summary['controls'] === []) {
            return $text."\n✅ Контрольных сообщений за день не было.\n";
        }

        $text .= "\nКонтрольные точки\n";
        foreach ($summary['controls'] as $control) {
            $label = $control['label'];
            $exceptionCount = collect($control['responses'])->whereIn('status', ['problem', 'partial'])->count();
            $text .= match ($control['status']) {
                'ok' => '✅ '.$label."\n",
                'problem' => '⚠️ '.$this->problemLabel($control['control_type'], $label).' — '.$exceptionCount.' '.$this->exceptionNoun($exceptionCount)."\n",
                'unknown' => '❔ '.$label.": ответы неоднозначны\n",
                default => '• '.$label.": нет данных\n",
            };
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
        } elseif ($summary['no_response_available'] === false) {
            $text .= "\nℹ️ Список ожидаемых участников не настроен; отсутствие ответа не рассчитывается.\n";
        }

        return htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

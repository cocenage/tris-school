<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TelegramScheduledControlSummaryFormatter
{
    public function format(array $summary): string
    {
        $totals = $summary['totals'];
        $text = '📊 Контрольные рассылки · '.CarbonImmutable::parse($summary['date'])->format('d.m.Y')."\n\nЗа день:\n";
        foreach (['controls' => 'Контрольных точек', 'responded' => 'С ответом', 'no_response' => 'Без ответа', 'problem' => 'С проблемами', 'partial' => 'Частично', 'pending' => 'Ожидаем ответ', 'not_delivered' => 'Не доставлено'] as $key => $label) {
            $text .= '• '.$label.': '.$totals[$key]."\n";
        }
        if ($totals['median_response_latency_seconds'] !== null) {
            $text .= 'Медиана ответа: '.$this->latency($totals['median_response_latency_seconds'])."\n";
        }
        $shown = 0;
        foreach ($summary['deliveries'] as $delivery) {
            $section = "\n".CarbonImmutable::parse($delivery['scheduled_for'])->format('H:i').' · '.Str::limit($delivery['name'], 90)."\n";
            $section .= 'Ответили: '.$delivery['unique_responder_count']." сотрудника\n";
            if ($delivery['unidentified_response_messages'] > 0) {
                $section .= 'Ответов без идентификатора автора: '.$delivery['unidentified_response_messages']."\n";
            }
            if ($delivery['median_response_latency_seconds'] !== null) {
                $section .= 'Медиана ответа: '.$this->latency($delivery['median_response_latency_seconds'])."\n";
            }
            foreach (['confirmed' => '✅ Подтверждено', 'problem' => '⚠️ Проблемы', 'partial' => '◐ Частично', 'unclear' => '❔ Неясно'] as $category => $label) {
                if ($delivery['classification_counts'][$category]) {
                    $section .= $label.': '.$delivery['classification_counts'][$category]." ответов\n";
                }
            }
            if ($delivery['result'] === 'no_response') {
                $section .= "❌ Без ответа\n";
            } elseif ($delivery['delivery_status'] !== 'sent') {
                $section .= "Сообщение не доставлено\n";
            } elseif ($delivery['result'] === 'pending') {
                $section .= "Окно ответа ещё открыто\n";
            }
            foreach ($delivery['exceptions'] as $exception) {
                $section .= '• '.($exception['author_name'] ? Str::limit($exception['author_name'], 50).' — ' : '').$exception['text']."\n";
            }
            if ($shown >= 12 || mb_strlen($text.$section) > 3400) {
                break;
            }
            $text .= $section;
            $shown++;
        }
        if ($shown < count($summary['deliveries'])) {
            $text .= "\nЕщё контрольных точек: ".(count($summary['deliveries']) - $shown).'. Подробности доступны в админке.';
        }

        return htmlspecialchars(trim($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function latency(float $seconds): string
    {
        return $seconds < 60 ? (string) round($seconds).' сек' : (string) round($seconds / 60, 1).' мин';
    }
}

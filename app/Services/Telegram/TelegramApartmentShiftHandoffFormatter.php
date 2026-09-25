<?php

namespace App\Services\Telegram;

class TelegramApartmentShiftHandoffFormatter
{
    /** @param array<int, array<string, mixed>> $items */
    public function format(array $items): string
    {
        $lines = ['🔄 На следующую смену'];

        foreach ($items as $item) {
            $lines[] = '';
            $lines[] = trim((string) $item['summary']);

            if (filled($item['author_name'] ?? null)) {
                $lines[] = '👤 '.trim((string) $item['author_name']);
            }

            if (filled($item['quote'] ?? null)) {
                $lines[] = '💬 «'.trim((string) $item['quote']).'»';
            }

            if (filled($item['next_action'] ?? null)) {
                $lines[] = '→ '.trim((string) $item['next_action']);
            }
        }

        return implode("\n", $lines);
    }
}

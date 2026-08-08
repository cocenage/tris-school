<?php

namespace App\Services\Telegram;

/**
 * Presentation-only builder for Telegram Rich Messages.
 * Business services provide semantic fields; this class owns the markup.
 */
class TelegramRichMessageBuilder
{
    public function build(
        string $title,
        string $status,
        array $fields = [],
        ?string $body = null,
        ?string $notice = null,
        array $results = [],
    ): array {
        $lines = ['# ' . $this->plain($title), '', '**' . $this->plain($status) . '**'];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = '**' . $this->plain((string) $label) . '**';
            $lines[] = $this->plain((string) $value);
        }

        if ($body !== null && trim($body) !== '') {
            $lines[] = '';
            $lines[] = '> ' . $this->plain($body);
        }

        if ($notice !== null && trim($notice) !== '') {
            $lines[] = '';
            $lines[] = '> ' . $this->plain($notice);
        }

        if ($results !== []) {
            $lines[] = '';
            $lines[] = '## Результат';

            foreach ($results as $result) {
                $lines[] = '- ' . $this->plain((string) $result);
            }
        }

        return ['markdown' => implode("\n", $lines)];
    }

    private function plain(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(
            ['\\', '*', '_', '[', ']', '(', ')', '`', '#', '>', '+', '-', '.', '!'],
            ['\\\\', '\\*', '\\_', '\\[', '\\]', '\\(', '\\)', '\\`', '\\#', '\\>', '\\+', '\\-', '\\.', '\\!'],
            trim($value),
        );
    }
}

<?php

namespace App\Services\Telegram;

/**
 * Presentation-only builder for Telegram Rich Messages.
 * Business services provide semantic fields; this class owns the markup.
 */
class TelegramRichMessageBuilder
{
    /** Count rendered HTML text in UTF-16 units, a conservative Telegram-safe measure for supplementary Unicode characters. */
    public static function htmlVisibleLength(string $html): int
    {
        $visibleText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($visibleText, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @return array{markdown: string} */
    public function markdownDocument(string $markdown): array
    {
        return ['markdown' => $markdown];
    }

    /** Escape untrusted text for inline insertion into Telegram Rich Markdown. */
    public function escapeRichText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value));
        $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['\\', '*', '_', '[', ']', '(', ')', '`', '#', '>', '+', '-', '.', '!', '|', '~', '='],
            ['\\\\', '\\*', '\\_', '\\[', '\\]', '\\(', '\\)', '\\`', '\\#', '\\>', '\\+', '\\-', '\\.', '\\!', '\\|', '\\~', '\\='],
            $value,
        );
    }

    public function build(
        string $title,
        string $status,
        array $fields = [],
        ?string $body = null,
        ?string $notice = null,
        array $results = [],
    ): array {
        $lines = ['# '.$this->plain($title), '', '**'.$this->plain($status).'**'];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = '**'.$this->plain((string) $label).'**';
            $lines[] = $this->plain((string) $value);
        }

        if ($body !== null && trim($body) !== '') {
            $lines[] = '';
            $lines[] = '> '.$this->plain($body);
        }

        if ($notice !== null && trim($notice) !== '') {
            $lines[] = '';
            $lines[] = '> '.$this->plain($notice);
        }

        if ($results !== []) {
            $lines[] = '';
            $lines[] = '## Результат';

            foreach ($results as $result) {
                $lines[] = '- '.$this->plain((string) $result);
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

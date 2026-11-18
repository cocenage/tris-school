<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TelegramOksRichCard
{
    public const CONTEXT_KEY = 'oks_rich_card';

    public static function available(): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', self::botUsername());
    }

    public static function enabled(TelegramScheduledMessageDelivery $delivery): bool
    {
        return ($delivery->sequence_context[self::CONTEXT_KEY] ?? false) === true;
    }

    public static function miniAppUrl(string $startParam): ?string
    {
        return self::available()
            ? 'https://t.me/'.self::botUsername().'?startapp='.$startParam.'&mode=compact'
            : null;
    }

    /** @param list<array{name: string, status: string, icon: string, notes?: list<string>}> $rows */
    public function render(TelegramScheduledMessageDelivery $delivery, string $title, array $rows, string $button, array $sections): array
    {
        $time = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome')->format('H:i');
        $title = ($delivery->isOksTest() ? 'ТЕСТ ОКС · ' : '').$time.' · '.$title;
        $html = ['<b>'.e($title).'</b>', ''];
        $compactHtml = $html;
        $rich = ['<h2>'.e($title).'</h2>'];
        foreach ($rows as $row) {
            $line = $row['icon'].' <b>'.e($row['name']).'</b> · '.e($row['status']);
            $rawNotes = $row['notes'] ?? [];
            $notes = array_map(fn (string $note): string => '<i>'.e($note).'</i>', $rawNotes);
            $shortNotes = array_map(fn (string $note): string => '<i>'.e(Str::limit($note, 145)).'</i>', $rawNotes);
            $html[] = implode("\n", [$line, ...$notes]);
            $compactHtml[] = implode("\n", [$line, ...$shortNotes]);
            $rich[] = '<p>'.implode('<br>', [$line, ...$notes]).'</p>';
        }
        if ($sections !== []) {
            $rich[] = '<details><summary>'.e($button).'</summary>';
            foreach ($sections as $section) {
                $rich[] = '<h3>'.e($section['name']).'</h3>';
                foreach ($section['questions'] as $question) {
                    if (filled($question['title'] ?? null)) {
                        $rich[] = '<p>'.e($question['title']).'</p>';
                    }
                    if (filled($question['details'] ?? null)) {
                        $rich[] = '<p><i>'.e($question['details']).'</i></p>';
                    }
                    $rich[] = '<tg-button-row>'.implode('', array_map(
                        fn (array $action): string => isset($action['url'])
                            ? '<tg-button type="url" url="'.e($action['url']).'">'.e($action['text']).'</tg-button>'
                            : '<tg-button type="callback_data" data="'.e($action['callback_data']).'">'.e($action['text']).'</tg-button>',
                        $question['buttons'],
                    )).'</tg-button-row>';
                }
            }
            $rich[] = '</details>';
        }
        $rich = ['html' => implode("\n", $rich)];
        $text = implode("\n", $html);
        if (TelegramRichMessageBuilder::htmlVisibleLength($text) > 4096) {
            $text = implode("\n", $compactHtml);
        }
        $url = self::miniAppUrl('oks_card_'.$delivery->id);
        $markup = ['inline_keyboard' => $url === null ? [] : [[['text' => $button, 'url' => $url]]]];

        return ['rich_message' => $rich, 'text' => $text, 'fallback_html' => $text, 'reply_markup' => $markup,
            'render_hash' => hash('sha256', serialize([$rich, $text, $markup]))];
    }

    private static function botUsername(): string
    {
        return trim((string) config('services.telegram.bot_username'), ' @');
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;

class TelegramOksRichCard
{
    public const CONTEXT_KEY = 'oks_rich_card';

    public function __construct(private readonly TelegramRichMessageBuilder $richMessages) {}

    public static function available(): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', self::botUsername());
    }

    public static function enabled(TelegramScheduledMessageDelivery $delivery): bool
    {
        return ($delivery->sequence_context[self::CONTEXT_KEY] ?? false) === true;
    }

    /** @param list<array{name: string, status: string, icon: string}> $rows */
    public function render(TelegramScheduledMessageDelivery $delivery, string $title, array $rows, string $button): array
    {
        $time = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome')->format('H:i');
        $title = ($delivery->isOksTest() ? 'ТЕСТ ОКС · ' : '').$time.' · '.$title;
        $markdown = ['# '.$this->richMessages->escapeRichText($title), ''];
        $html = ['<b>'.e($title).'</b>', ''];
        foreach ($rows as $row) {
            $name = $row['name'];
            $status = $row['status'];
            $markdown[] = '- '.$row['icon'].' **'.$this->richMessages->escapeRichText($name).'** · '.$this->richMessages->escapeRichText($status);
            $html[] = $row['icon'].' <b>'.e($name).'</b> · '.e($status);
        }
        $rich = $this->richMessages->markdownDocument(implode("\n", $markdown));
        $text = implode("\n", $html);
        $markup = ['inline_keyboard' => self::available() ? [[[
            'text' => $button,
            'url' => 'https://t.me/'.self::botUsername().'?startapp=oks_card_'.$delivery->id.'&mode=compact',
        ]]] : []];

        return ['rich_message' => $rich, 'text' => $text, 'fallback_html' => $text, 'reply_markup' => $markup,
            'render_hash' => hash('sha256', serialize([$rich, $text, $markup]))];
    }

    private static function botUsername(): string
    {
        return trim((string) config('services.telegram.bot_username'), ' @');
    }
}

<?php

namespace App\Services\Telegram;

class ScheduledControlTypes
{
    public const CLOSING_CLEANINGS = 'second_cleanings_finishing';

    public const CLOSING_PAYMENTS = 'extra_payments_completed';

    public const LABELS = [
        'schedule_checked' => 'Время уборок и заметки проверены',
        'first_cleanings_started' => 'Все первые уборки начались',
        'first_cleanings_finishing' => 'Первые уборки подходят к завершению',
        'second_cleanings_finishing' => 'Вторые уборки подходят к завершению',
        'couriers_completed' => 'Курьеры завершили все доставки',
        'extra_payments_completed' => 'Все доплаты произведены',
    ];

    public static function options(): array
    {
        return self::LABELS;
    }

    public static function scheduledTime(string $controlType, string $configuredTime): ?string
    {
        return match ($controlType) {
            self::CLOSING_CLEANINGS => '19:00:00',
            self::CLOSING_PAYMENTS => null,
            default => $configuredTime,
        };
    }

    public static function supportsInlineAnswer(string $controlType): bool
    {
        return in_array($controlType, [self::CLOSING_CLEANINGS, self::CLOSING_PAYMENTS], true);
    }
}

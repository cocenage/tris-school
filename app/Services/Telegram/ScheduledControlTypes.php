<?php

namespace App\Services\Telegram;

class ScheduledControlTypes
{
    public const DISTRICTS = [
        'lambrate' => 'Lambrate',
        'lodi' => 'Lodi',
        'certosa' => 'Certosa',
        'navigli' => 'Navigli',
        'como' => 'Como',
    ];

    public const CLOSING_CLEANINGS = 'cleanings_completed';

    public const SECOND_CLEANINGS_FINISHING = 'second_cleanings_finishing';

    public const CLOSING_PAYMENTS = 'extra_payments_completed';

    public const LABELS = [
        'schedule_checked' => 'Время уборок и заметки проверены',
        'first_cleanings_started' => 'Все первые уборки начались',
        'first_cleanings_finishing' => 'Первые уборки подходят к завершению',
        'second_cleanings_finishing' => 'Вторые уборки подходят к завершению',
        'cleanings_completed' => 'Все уборки завершены',
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
            self::CLOSING_PAYMENTS => null,
            default => $configuredTime,
        };
    }

    public static function supportsInlineAnswer(string $controlType): bool
    {
        return in_array($controlType, [self::CLOSING_CLEANINGS, self::SECOND_CLEANINGS_FINISHING, self::CLOSING_PAYMENTS], true);
    }

    public static function triggersClosingPayments(string $controlType): bool
    {
        return in_array($controlType, [self::CLOSING_CLEANINGS, self::SECOND_CLEANINGS_FINISHING], true);
    }

    public static function districtKeyboard(int $deliveryId): array
    {
        return ['inline_keyboard' => collect(self::DISTRICTS)->map(fn (string $label, string $key): array => [
            ['text' => $label.' · ✅ Да', 'callback_data' => "sc:$deliveryId:$key:yes"],
            ['text' => $label.' · ❌ Нет', 'callback_data' => "sc:$deliveryId:$key:no"],
        ])->values()->all()];
    }
}

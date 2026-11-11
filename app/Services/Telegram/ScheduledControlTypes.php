<?php

namespace App\Services\Telegram;

class ScheduledControlTypes
{
    private const SEQUENTIAL_SLOTS = [
        'first_cleanings' => [
            'first' => '12:00:00', 'second' => '12:30:00',
            'types' => ['first_cleanings_started', 'first_cleanings_finishing'],
        ],
        'second_cleanings' => [
            'first' => '14:00:00', 'second' => '14:30:00',
            'types' => ['second_cleanings_finishing'],
        ],
        'courier_deliveries' => [
            'first' => '16:00:00', 'second' => '16:30:00',
            'types' => ['couriers_completed'],
        ],
    ];

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
        return $controlType === self::CLOSING_CLEANINGS;
    }

    /** @return array{pair: string, stage: 'first'|'second', previous_time: string, next_time: string, types: list<string>}|null */
    public static function sequentialStage(string $controlType, string $configuredTime): ?array
    {
        $time = self::normalizeTime($configuredTime);

        foreach (self::SEQUENTIAL_SLOTS as $pair => $slots) {
            if (! in_array($controlType, $slots['types'], true)) {
                continue;
            }
            if ($time === $slots['first']) {
                return [
                    'pair' => $pair, 'stage' => 'first', 'previous_time' => $slots['first'],
                    'next_time' => $slots['second'], 'types' => $slots['types'],
                ];
            }
            if ($time === $slots['second']) {
                return [
                    'pair' => $pair, 'stage' => 'second', 'previous_time' => $slots['first'],
                    'next_time' => $slots['second'], 'types' => $slots['types'],
                ];
            }
        }

        return null;
    }

    public static function normalizeTime(string $time): string
    {
        $parts = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return sprintf('%02d:%02d:%02d', $parts[0], $parts[1], $parts[2]);
    }

    /** @param array<string, array<string, array{classification: string}>> $states */
    public static function districtKeyboard(int $deliveryId, ?array $districtScope = null, array $states = []): array
    {
        $districtScope ??= array_keys(self::DISTRICTS);
        $rows = [];

        foreach ($districtScope as $key) {
            if (! isset(self::DISTRICTS[$key])) {
                continue;
            }
            $label = self::DISTRICTS[$key];
            $answers = collect($states[$key] ?? []);
            $classifications = $answers->pluck('classification')->unique()->values();
            $selected = $classifications->count() === 1 ? $classifications->first() : null;
            $yesStyle = $selected === 'confirmed' ? 'success' : null;
            $noStyle = $selected === 'problem' ? 'danger' : null;
            $yes = ['text' => $label.' · ✅ Да', 'callback_data' => "sc:$deliveryId:$key:yes"];
            $no = ['text' => $label.' · ❌ Нет', 'callback_data' => "sc:$deliveryId:$key:no"];
            if ($yesStyle !== null) {
                $yes['style'] = $yesStyle;
            }
            if ($noStyle !== null) {
                $no['style'] = $noStyle;
            }
            $rows[] = [$yes, $no];
        }

        return ['inline_keyboard' => $rows];
    }
}

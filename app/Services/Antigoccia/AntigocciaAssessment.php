<?php

namespace App\Services\Antigoccia;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AntigocciaAssessment
{
    public const SURFACES = [
        'mirrors' => 'ЗЕРКАЛА',
        'shower_outside' => 'Стекло душевой кабины СНАРУЖИ',
        'shower_inside' => 'Стекло ВНУТРИ ДУШЕВОЙ КАБИНЫ',
        'shower_walls' => 'СТЕНЫ ВНУТРИ ДУШЕВОЙ КАБИНЫ',
    ];

    // String keys prevent confusing the new choices with legacy numeric 0/1/2.
    public const REMOVAL_OPTIONS = [
        'easy' => ['icon' => '🟢', 'label' => 'Легко', 'description' => 'достаточно 1–2 движений.'],
        'medium' => ['icon' => '🟡', 'label' => 'Средне', 'description' => 'требуется несколько повторных движений.'],
        'hard' => ['icon' => '🔴', 'label' => 'Трудно', 'description' => 'загрязнения удаляются с усилием или остаются.'],
        'no_dirt' => ['icon' => '⚪️', 'label' => 'Загрязнений не было', 'description' => 'Поверхность всё равно протёрта влажной салфеткой.'],
    ];

    public static function blankAnswers(): array
    {
        return array_fill_keys(array_keys(self::SURFACES), [
            'dirt' => null, 'removal' => null, 'remaining' => '',
        ]);
    }

    /**
     * All three answers are independent and always required. Only "remaining:
     * yes" makes the photograph of that particular surface mandatory.
     */
    public function validate(array $answers, array $photos): array
    {
        $input = ['answers' => [], 'photos' => []];
        $rules = [];

        foreach (self::SURFACES as $key => $label) {
            $input['answers'][$key] = [
                'dirt' => data_get($answers, "$key.dirt"),
                'removal' => data_get($answers, "$key.removal"),
                'remaining' => data_get($answers, "$key.remaining"),
            ];
            $input['photos'][$key] = $photos[$key] ?? null;
            $rules["answers.$key.dirt"] = ['required', Rule::in([0, 1, 2])];
            $rules["answers.$key.removal"] = ['required', Rule::in(array_keys(self::REMOVAL_OPTIONS))];
            $rules["answers.$key.remaining"] = ['required', Rule::in(['yes', 'no'])];
            $rules["photos.$key"] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
        }

        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($input): void {
            foreach (self::SURFACES as $key => $label) {
                if (data_get($input, "answers.$key.remaining") === 'yes'
                    && ! ($input['photos'][$key] ?? null)) {
                    $validator->errors()->add("photos.$key", "Добавьте фото оставшихся загрязнений: $label.");
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized = [];
        foreach (self::SURFACES as $key => $label) {
            $normalized[$key] = [
                'dirt' => (int) $input['answers'][$key]['dirt'],
                'removal' => (string) $input['answers'][$key]['removal'],
                'remaining' => (string) $input['answers'][$key]['remaining'],
            ];
        }

        return $normalized;
    }

    public static function removalLabel(mixed $value): string
    {
        if (is_string($value) && isset(self::REMOVAL_OPTIONS[$value])) {
            $option = self::REMOVAL_OPTIONS[$value];

            return $option['label'].' — '.$option['description'];
        }

        return match ($value) {
            0, '0' => 'Легко (старая анкета)',
            1, '1' => 'Трудно (старая анкета)',
            2, '2' => 'Не удалились (старая анкета)',
            default => '—',
        };
    }

    public static function isDifficultRemoval(mixed $value): bool
    {
        return in_array($value, ['medium', 'hard', 1, '1', 2, '2'], true);
    }

    public static function isHardRemoval(mixed $value): bool
    {
        return in_array($value, ['hard', 2, '2'], true);
    }

    public static function removalIndicator(mixed $value): array
    {
        return match ($value) {
            'easy', 0, '0' => ['label' => 'Л', 'color' => 'bg-green-100 text-green-800'],
            'medium' => ['label' => 'С', 'color' => 'bg-amber-100 text-amber-800'],
            'hard' => ['label' => 'Т', 'color' => 'bg-red-100 text-red-800'],
            'no_dirt' => ['label' => '—', 'color' => 'bg-gray-100 text-gray-500 dark:bg-gray-800'],
            1, '1' => ['label' => '1', 'color' => 'bg-amber-100 text-amber-800'],
            2, '2' => ['label' => '2', 'color' => 'bg-red-100 text-red-800'],
            default => ['label' => '—', 'color' => 'bg-gray-100 text-gray-500 dark:bg-gray-800'],
        };
    }
}

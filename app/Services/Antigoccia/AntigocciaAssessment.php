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

    public static function blankAnswers(): array
    {
        return array_fill_keys(array_keys(self::SURFACES), [
            'dirt' => null, 'removal' => null, 'remaining' => '',
        ]);
    }

    /**
     * All four surfaces are independently required. Photo is required per
     * surface if contamination remains, including when removal was scored 2.
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
            $rules["answers.$key.removal"] = ['nullable', Rule::in([0, 1, 2])];
            $rules["answers.$key.remaining"] = ['required', Rule::in(['yes', 'no'])];
            $rules["photos.$key"] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
        }

        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($input): void {
            foreach (self::SURFACES as $key => $label) {
                $dirt = data_get($input, "answers.$key.dirt");
                $removal = data_get($input, "answers.$key.removal");
                $remaining = data_get($input, "answers.$key.remaining");
                if (! in_array((string) $dirt, ['0', '1', '2'], true)) {
                    continue;
                }
                if ((int) $dirt > 0 && ! in_array((string) $removal, ['0', '1', '2'], true)) {
                    $validator->errors()->add("answers.$key.removal", "Укажите результат очистки: $label.");
                }
                if ((int) $dirt > 0 && (string) $removal === '2' && $remaining !== 'yes') {
                    $validator->errors()->add("answers.$key.remaining", "Остались загрязнения — выберите «Да»: $label.");
                }
                if ($remaining === 'yes' && ! ($input['photos'][$key] ?? null)) {
                    $validator->errors()->add("photos.$key", "Добавьте фото оставшихся загрязнений: $label.");
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized = [];
        foreach (self::SURFACES as $key => $label) {
            $dirt = (int) $input['answers'][$key]['dirt'];
            $normalized[$key] = [
                'dirt' => $dirt,
                'removal' => $dirt === 0 ? null : (int) $input['answers'][$key]['removal'],
                'remaining' => $input['answers'][$key]['remaining'],
            ];
        }

        return $normalized;
    }
}

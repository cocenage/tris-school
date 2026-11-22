<?php

namespace App\Services\Antigoccia;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AntigocciaAssessment
{
    public const SURFACES = [
        'mirrors' => 'Зеркала',
        'shower_outside' => 'Стекло душевой снаружи',
        'shower_inside' => 'Стекло душевой внутри',
        'shower_walls' => 'Стены внутри душевой',
    ];

    public static function blankAnswers(): array
    {
        return array_fill_keys(array_keys(self::SURFACES), ['dirt' => null, 'removal' => null]);
    }

    /**
     * Cleans and validates answers against the canonical four-surface questionnaire.
     * A zero dirt score always means cleaning was not required (null removal).
     * Photo requirements are checked against uploaded file objects, not client paths.
     */
    public function validate(array $answers, string $remaining, array $photos, mixed $generalPhoto): array
    {
        $rules = ['remaining' => ['required', Rule::in(['yes', 'no'])]];
        $input = ['remaining' => $remaining];

        foreach (self::SURFACES as $key => $label) {
            $input['answers'][$key]['dirt'] = data_get($answers, "$key.dirt");
            $input['answers'][$key]['removal'] = data_get($answers, "$key.removal");
            $rules["answers.$key.dirt"] = ['required', Rule::in([0, 1, 2])];
            $rules["answers.$key.removal"] = ['nullable', Rule::in([0, 1, 2])];
            $rules["photos.$key"] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
            $input['photos'][$key] = $photos[$key] ?? null;
        }

        $rules['generalPhoto'] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
        $input['generalPhoto'] = $generalPhoto;
        $validator = Validator::make($input, $rules);

        $validator->after(function ($validator) use ($input): void {
            $anyNotRemoved = false;
            $hasRequiredSurfacePhoto = false;

            foreach (self::SURFACES as $key => $label) {
                $dirt = data_get($input, "answers.$key.dirt");
                $removal = data_get($input, "answers.$key.removal");
                if (! in_array((string) $dirt, ['0', '1', '2'], true)) {
                    continue;
                }
                if ((int) $dirt !== 0 && ! in_array((string) $removal, ['0', '1', '2'], true)) {
                    $validator->errors()->add("answers.$key.removal", "Укажите результат очистки: $label.");
                }
                if ((int) $dirt !== 0 && (string) $removal === '2') {
                    $anyNotRemoved = true;
                    if (! data_get($input, "photos.$key")) {
                        $validator->errors()->add("photos.$key", "Добавьте фотографию поверхности: $label.");
                    } else {
                        $hasRequiredSurfacePhoto = true;
                    }
                }
            }

            if ($anyNotRemoved && $input['remaining'] !== 'yes') {
                $validator->errors()->add('remaining', 'Если загрязнение не удалилось, выберите «Да».');
            }
            if ($input['remaining'] === 'yes' && ! $input['generalPhoto'] && ! $hasRequiredSurfacePhoto) {
                $validator->errors()->add('generalPhoto', 'Приложите фото оставшихся загрязнений.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $normalized = [];
        foreach (self::SURFACES as $key => $label) {
            $dirt = (int) data_get($input, "answers.$key.dirt");
            $normalized[$key] = [
                'dirt' => $dirt,
                'removal' => $dirt === 0 ? null : (int) data_get($input, "answers.$key.removal"),
            ];
        }

        return $normalized;
    }
}

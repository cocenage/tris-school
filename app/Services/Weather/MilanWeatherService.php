<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MilanWeatherService
{
    protected int $shiftStartHour = 8;

    protected int $shiftEndHour = 17;

    public function today(
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): array {
        return $this->forecast($latitude, $longitude, $timezone, ['forecast_days' => 1]);
    }

    public function forDate(
        CarbonImmutable $date,
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): ?array {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $target = $date->setTimezone($timezone)->startOfDay();
        $daysAhead = $today->diffInDays($target, false);

        if ($daysAhead < 0 || $daysAhead > 15) {
            return null;
        }

        $result = $this->forecast($latitude, $longitude, $timezone, [
            'start_date' => $target->toDateString(),
            'end_date' => $target->toDateString(),
        ]);

        return $result['summary'] === 'погода временно недоступна' ? null : $result;
    }

    public function morningForecast(
        CarbonImmutable $date,
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): array {
        $target = $date->setTimezone($timezone)->startOfDay();
        $points = ['08:00' => null, '13:00' => null, '15:00' => null];

        $data = $this->requestHourly($latitude, $longitude, $timezone, [
            'hourly' => implode(',', [
                'temperature_2m',
                'apparent_temperature',
                'relative_humidity_2m',
                'precipitation_probability',
                'weather_code',
                'wind_speed_10m',
                'wind_direction_10m',
                'rain',
            ]),
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'timeformat' => 'unixtime',
            'start_date' => $target->toDateString(),
            'end_date' => $target->toDateString(),
        ]);

        $timestamps = $data['hourly']['time'] ?? null;
        if (! is_array($timestamps)) {
            if ($data !== null) {
                Log::warning('Weather provider returned no usable hourly timestamps.');
            }

            return ['points' => $points, 'advice' => null];
        }

        $hourly = $data['hourly'];
        foreach ($timestamps as $index => $timestamp) {
            if (! is_numeric($timestamp)) {
                continue;
            }

            $localTime = CarbonImmutable::createFromTimestampUTC((int) $timestamp)->setTimezone($timezone);
            if ($localTime->toDateString() !== $target->toDateString()) {
                continue;
            }

            $key = $localTime->format('H:00');
            if (! array_key_exists($key, $points) || $points[$key] !== null) {
                continue;
            }

            $code = $this->nullableInteger($this->hourlyValue($hourly, 'weather_code', $index));
            [$condition, $emoji] = $this->weatherCondition($code);
            $point = [
                'condition' => $condition,
                'emoji' => $emoji,
                'weather_code' => $code,
                'temperature_c' => $this->nullableFloat($this->hourlyValue($hourly, 'temperature_2m', $index)),
                'apparent_temperature_c' => $this->nullableFloat($this->hourlyValue($hourly, 'apparent_temperature', $index)),
                'relative_humidity_pct' => $this->nullableInteger($this->hourlyValue($hourly, 'relative_humidity_2m', $index)),
                'wind_speed_kmh' => $this->nullableFloat($this->hourlyValue($hourly, 'wind_speed_10m', $index)),
                'wind_direction' => $this->windDirection($this->nullableFloat($this->hourlyValue($hourly, 'wind_direction_10m', $index))),
                'precipitation_probability_pct' => $this->nullableInteger($this->hourlyValue($hourly, 'precipitation_probability', $index)),
                'rain_mm' => $this->nullableFloat($this->hourlyValue($hourly, 'rain', $index)),
            ];

            if (count(array_filter($point, fn (mixed $value): bool => $value !== null)) > 0) {
                $points[$key] = $point;
            }
        }

        return [
            'points' => $points,
            'advice' => $this->morningAdvice($points),
        ];
    }

    private function forecast(float $latitude, float $longitude, string $timezone, array $period): array
    {
        $data = $this->requestHourly($latitude, $longitude, $timezone, array_merge([
            'hourly' => 'temperature_2m,precipitation_probability,rain,weather_code,wind_speed_10m',
        ], $period));

        return $data === null ? $this->fallback() : $this->formatShiftWeather($data);
    }

    private function requestHourly(float $latitude, float $longitude, string $timezone, array $parameters): ?array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Log::warning('Weather request skipped: invalid coordinates.');

            return null;
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get('https://api.open-meteo.com/v1/forecast', array_merge([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timezone' => $timezone,
                ], $parameters));
        } catch (\Throwable $e) {
            Log::warning('Weather request failed', [
                'exception' => class_basename($e),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Weather provider returned an unsuccessful response.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ! is_array($data['hourly'] ?? null)) {
            Log::warning('Weather provider returned an unusable hourly forecast.');

            return null;
        }

        return $data;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function hourlyValue(array $hourly, string $field, int|string $index): mixed
    {
        $values = $hourly[$field] ?? null;

        return is_array($values) ? ($values[$index] ?? null) : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function windDirection(?float $degrees): ?string
    {
        if ($degrees === null) {
            return null;
        }

        $directions = ['С', 'СВ', 'В', 'ЮВ', 'Ю', 'ЮЗ', 'З', 'СЗ'];
        $sector = (int) floor((fmod(($degrees + 360), 360) + 22.5) / 45) % 8;

        return $directions[$sector];
    }

    private function weatherCondition(?int $code): array
    {
        return match ($code) {
            0 => ['Ясно', '☀️'],
            1 => ['Преимущественно ясно', '🌤'],
            2 => ['Облачно', '🌥'],
            3 => ['Пасмурно', '☁️'],
            45, 48 => ['Туман', '🌫'],
            51, 53, 55 => ['Морось', '🌦'],
            56, 57 => ['Ледяная морось', '🌧'],
            61, 63 => ['Дождь', '🌧'],
            65 => ['Сильный дождь', '🌧'],
            66, 67 => ['Ледяной дождь', '🌧'],
            71, 73, 75, 77 => ['Снег', '❄️'],
            80, 81 => ['Кратковременный дождь', '🌦'],
            82 => ['Сильный ливень', '🌧'],
            85, 86 => ['Снегопад', '🌨'],
            95 => ['Гроза', '⛈'],
            96, 99 => ['Гроза с градом', '⛈'],
            default => [null, null],
        };
    }

    private function morningAdvice(array $points): ?string
    {
        foreach ($points as $time => $point) {
            if ($point === null) {
                continue;
            }

            $code = $point['weather_code'];
            if (in_array($code, [95, 96, 99], true)) {
                return "⚠️ Прогноз указывает на грозу около {$time}; учитывайте это при перемещении между квартирами.";
            }

            if (in_array($code, [56, 57, 66, 67, 71, 73, 75, 77, 85, 86], true)) {
                return "⚠️ Прогноз снега или гололёда около {$time}; планируйте перемещения осторожно.";
            }

            if ($point['wind_speed_kmh'] !== null && $point['wind_speed_kmh'] >= 50) {
                return "⚠️ Около {$time} ожидается очень сильный ветер (от 50 км/ч); учитывайте условия на улице.";
            }

            $isRain = in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true);
            if (in_array($code, [65, 82], true)) {
                return "⚠️ Около {$time} прогнозируется сильный дождь; возможны задержки в пути.";
            }
            if ($point['rain_mm'] !== null && $point['rain_mm'] >= 4) {
                return "⚠️ Около {$time} прогнозируется интенсивный дождь; возможны задержки в пути.";
            }
            if ($isRain && $point['precipitation_probability_pct'] !== null
                && $point['precipitation_probability_pct'] >= 75) {
                return "⚠️ Около {$time} высока вероятность дождя; учитывайте возможные задержки в пути.";
            }

            if ($point['temperature_c'] !== null && $point['temperature_c'] >= 35) {
                return "⚠️ Около {$time} прогнозируется экстремальная жара; чаще делайте перерывы и пейте воду.";
            }
            if ($point['temperature_c'] !== null && $point['temperature_c'] <= -10) {
                return "⚠️ Около {$time} прогнозируется сильный мороз; учитывайте это при перемещении.";
            }
        }

        return null;
    }

    protected function formatShiftWeather(array $data): array
    {
        $hours = $data['hourly']['time'] ?? [];
        $temps = $data['hourly']['temperature_2m'] ?? [];
        $rain = $data['hourly']['rain'] ?? [];
        $probability = $data['hourly']['precipitation_probability'] ?? [];
        $codes = $data['hourly']['weather_code'] ?? [];
        $wind = $data['hourly']['wind_speed_10m'] ?? [];

        $shiftTemps = [];
        $shiftCodes = [];
        $maxRainProbability = 0;
        $totalRain = 0;
        $maxWind = 0;
        $rainStartHour = null;

        foreach ($hours as $index => $time) {
            $hour = (int) date('H', strtotime($time));

            if ($hour < $this->shiftStartHour || $hour > $this->shiftEndHour) {
                continue;
            }

            $temp = $temps[$index] ?? null;

            if ($temp !== null) {
                $shiftTemps[] = (float) $temp;
            }

            $rainValue = (float) ($rain[$index] ?? 0);
            $probabilityValue = (int) ($probability[$index] ?? 0);
            $windValue = (float) ($wind[$index] ?? 0);

            $totalRain += $rainValue;
            $maxRainProbability = max($maxRainProbability, $probabilityValue);
            $maxWind = max($maxWind, $windValue);

            if ($rainStartHour === null && ($rainValue > 0 || $probabilityValue >= 50)) {
                $rainStartHour = $hour;
            }

            if (isset($codes[$index])) {
                $shiftCodes[] = (int) $codes[$index];
            }
        }

        if (empty($shiftTemps)) {
            return $this->fallback();
        }

        $minTemp = (int) round(min($shiftTemps));
        $maxTemp = (int) round(max($shiftTemps));
        $mainCode = $this->mainWeatherCode($shiftCodes);

        return $this->buildResult(
            minTemp: $minTemp,
            maxTemp: $maxTemp,
            mainCode: $mainCode,
            maxRainProbability: $maxRainProbability,
            totalRain: $totalRain,
            maxWind: $maxWind,
            rainStartHour: $rainStartHour
        );
    }

    protected function buildResult(
        int $minTemp,
        int $maxTemp,
        ?int $mainCode,
        int $maxRainProbability,
        float $totalRain,
        float $maxWind,
        ?int $rainStartHour
    ): array {
        $tempText = $minTemp === $maxTemp
            ? sprintf('%+d°C', $maxTemp)
            : sprintf('%+d…%+d°C', $minTemp, $maxTemp);

        $hasRain = $totalRain > 0 || $maxRainProbability >= 50;
        $hasHeavyRain = $totalRain >= 4 || $maxRainProbability >= 75;
        $isHot = $maxTemp >= 30;
        $isColdMorning = $minTemp <= 8;
        $isWindy = $maxWind >= 30;

        $isIcy = in_array($mainCode, [56, 57, 66, 67], true);
        if ($isIcy || in_array($mainCode, [71, 73, 75, 77, 85, 86], true)) {
            return [
                'emoji' => '❄️',
                'summary' => $tempText.($isIcy ? ', гололёд' : ', снег'),
                'advice' => '❄️ На дорогу между квартирами лучше заложить дополнительное время.',
            ];
        }

        if ($hasHeavyRain) {
            return [
                'emoji' => '⛈',
                'summary' => $rainStartHour
                    ? "{$tempText}, после {$rainStartHour}:00 сильный дождь"
                    : "{$tempText}, сильный дождь",
                'advice' => $rainStartHour !== null && $rainStartHour >= 12
                    ? '🌧 После обеда лучше заложить больше времени на дорогу между квартирами.'
                    : '🌧 На дорогу между квартирами лучше заложить больше времени.',
            ];
        }

        if ($isWindy) {
            return [
                'emoji' => '💨',
                'summary' => "{$tempText}, сильный ветер",
                'advice' => '💨 На улице сильный ветер — аккуратнее с балконами, окнами и перемещением между квартирами.',
            ];
        }

        if ($hasRain) {
            return [
                'emoji' => '🌧',
                'summary' => $rainStartHour
                    ? "{$tempText}, дождь после {$rainStartHour}:00"
                    : "{$tempText}, возможен дождь",
                'advice' => null,
            ];
        }

        if ($isHot) {
            return [
                'emoji' => '☀️',
                'summary' => "{$tempText}, жарко",
                'advice' => null,
            ];
        }

        if ($isColdMorning) {
            return [
                'emoji' => '🥶',
                'summary' => "{$tempText}, прохладно утром",
                'advice' => null,
            ];
        }

        if (in_array($mainCode, [0, 1], true)) {
            return [
                'emoji' => '☀️',
                'summary' => "{$tempText}, солнечно",
                'advice' => null,
            ];
        }

        if (in_array($mainCode, [2, 3], true)) {
            return [
                'emoji' => '🌤',
                'summary' => "{$tempText}, облачно",
                'advice' => null,
            ];
        }

        return [
            'emoji' => '🌤',
            'summary' => $tempText,
            'advice' => null,
        ];
    }

    protected function mainWeatherCode(array $codes): ?int
    {
        if (empty($codes)) {
            return null;
        }

        $priority = [
            95, 96, 99,
            56, 57, 66, 67, 71, 73, 75, 77, 85, 86,
            80, 81, 82,
            61, 63, 65,
            51, 53, 55,
            45, 48,
            3, 2, 1, 0,
        ];

        foreach ($priority as $code) {
            if (in_array($code, $codes, true)) {
                return $code;
            }
        }

        return $codes[0] ?? null;
    }

    protected function fallback(): array
    {
        return [
            'emoji' => '🌤',
            'summary' => 'погода временно недоступна',
            'advice' => null,
        ];
    }
}

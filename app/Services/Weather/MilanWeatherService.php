<?php

namespace App\Services\Weather;

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
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Log::warning('Weather request skipped: invalid coordinates.');

            return $this->fallback();
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'hourly' => 'temperature_2m,precipitation_probability,rain,weather_code,wind_speed_10m',
                    'timezone' => $timezone,
                    'forecast_days' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Weather request failed', [
                'exception' => class_basename($e),
            ]);

            return $this->fallback();
        }

        if (! $response->successful()) {
            Log::warning('Weather provider returned an unsuccessful response.', [
                'status' => $response->status(),
            ]);

            return $this->fallback();
        }

        return $this->formatShiftWeather($response->json());
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

<?php

namespace App\Services\Weather;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MilanWeatherService
{
    protected int $shiftStartHour = 8;

    protected int $shiftEndHour = 17;

    protected array $rainAdvice = [
        '☂️ Не забудьте зонтик',
        '🌧 Сегодня пригодится зонт',
        '☔ Лучше взять что-нибудь непромокаемое',
        '🚶 На улице мокро, будьте осторожны',
    ];

    protected array $heavyRainAdvice = [
        '☂️ Возьмите зонтик и заложите немного больше времени на дорогу',
        '🌧 Возможны задержки из-за погоды',
        '🚦 Из-за дождя движение может быть медленнее обычного',
    ];

    protected array $hotAdvice = [
        '💧 Не забывайте пить воду',
        '🥤 Сегодня будет жарко',
        '☀️ Старайтесь не оставаться долго на солнце',
        '🌡 Жаркий день впереди',
    ];

    protected array $coldAdvice = [
        '🧥 Утром может быть прохладно',
        '🌬 Возьмите что-нибудь потеплее',
    ];

    protected array $windAdvice = [
        '💨 Сегодня ветрено',
        '🌬 На улице сильнее ветер, чем обычно',
    ];

    protected array $goodWeatherAdvice = [
        '😎 Сегодня отличная погода',
        '🌤 Погода радует',
        '☀️ Приятный день впереди',
        '🙂 С погодой сегодня повезло',
    ];

    public function today(): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => 45.4642,
                    'longitude' => 9.1900,
                    'hourly' => 'temperature_2m,precipitation_probability,rain,weather_code,wind_speed_10m',
                    'timezone' => 'Europe/Rome',
                    'forecast_days' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Milan weather request failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallback();
        }

        if (! $response->successful()) {
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
            ? "+{$maxTemp}°C"
            : "+{$minTemp}…+{$maxTemp}°C";

        $hasRain = $totalRain > 0 || $maxRainProbability >= 50;
        $hasHeavyRain = $totalRain >= 4 || $maxRainProbability >= 75;
        $isHot = $maxTemp >= 30;
        $isColdMorning = $minTemp <= 8;
        $isWindy = $maxWind >= 30;

        if ($hasHeavyRain) {
            return [
                'emoji' => '⛈',
                'summary' => $rainStartHour
                    ? "{$tempText}, сильный дождь после {$rainStartHour}:00"
                    : "{$tempText}, сильный дождь",
                'advice' => Arr::random($this->heavyRainAdvice),
            ];
        }

        if ($hasRain) {
            return [
                'emoji' => '🌧',
                'summary' => $rainStartHour
                    ? "{$tempText}, дождь после {$rainStartHour}:00"
                    : "{$tempText}, возможен дождь",
                'advice' => Arr::random($this->rainAdvice),
            ];
        }

        if ($isHot) {
            return [
                'emoji' => '☀️',
                'summary' => "{$tempText}, жарко",
                'advice' => Arr::random($this->hotAdvice),
            ];
        }

        if ($isColdMorning) {
            return [
                'emoji' => '🥶',
                'summary' => "{$tempText}, прохладно утром",
                'advice' => Arr::random($this->coldAdvice),
            ];
        }

        if ($isWindy) {
            return [
                'emoji' => '💨',
                'summary' => "{$tempText}, ветрено",
                'advice' => Arr::random($this->windAdvice),
            ];
        }

        if (in_array($mainCode, [0, 1], true)) {
            return [
                'emoji' => '☀️',
                'summary' => "{$tempText}, солнечно",
                'advice' => Arr::random($this->goodWeatherAdvice),
            ];
        }

        if (in_array($mainCode, [2, 3], true)) {
            return [
                'emoji' => '🌤',
                'summary' => "{$tempText}, облачно",
                'advice' => Arr::random($this->goodWeatherAdvice),
            ];
        }

        return [
            'emoji' => '🌤',
            'summary' => $tempText,
            'advice' => Arr::random($this->goodWeatherAdvice),
        ];
    }

    protected function mainWeatherCode(array $codes): ?int
    {
        if (empty($codes)) {
            return null;
        }

        $priority = [
            95, 96, 99,
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
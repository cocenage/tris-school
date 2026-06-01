<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
class MilanWeatherService
{
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
                    'current' => 'temperature_2m,weather_code',
                    'hourly' => 'temperature_2m,precipitation_probability,rain,weather_code',
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

        $data = $response->json();

        $currentTemp = round($data['current']['temperature_2m'] ?? 0);
        $currentCode = $data['current']['weather_code'] ?? null;

        $hours = $data['hourly']['time'] ?? [];
        $rain = $data['hourly']['rain'] ?? [];
        $probability = $data['hourly']['precipitation_probability'] ?? [];

        $shiftRain = false;
        $maxProbability = 0;

        foreach ($hours as $index => $time) {
            $hour = (int) date('H', strtotime($time));

            if ($hour < 10 || $hour > 17) {
                continue;
            }

            $rainValue = (float) ($rain[$index] ?? 0);
            $probabilityValue = (int) ($probability[$index] ?? 0);

            $maxProbability = max($maxProbability, $probabilityValue);

            if ($rainValue > 0 || $probabilityValue >= 50) {
                $shiftRain = true;
            }
        }

        return $this->formatWeather($currentTemp, $currentCode, $shiftRain, $maxProbability);
    }

protected function formatWeather(
    int $temp,
    ?int $code,
    bool $shiftRain,
    int $rainProbability
): array {
    if ($shiftRain && $rainProbability >= 70) {
        return [
            'emoji' => '⛈',
            'summary' => "+{$temp}°C, сильный дождь",
            'advice' => Arr::random($this->heavyRainAdvice),
        ];
    }

    if ($shiftRain) {
        return [
            'emoji' => '🌧',
            'summary' => "+{$temp}°C, дождь",
            'advice' => Arr::random($this->rainAdvice),
        ];
    }

    if ($temp >= 30) {
        return [
            'emoji' => '☀️',
            'summary' => "+{$temp}°C, жарко",
            'advice' => Arr::random($this->hotAdvice),
        ];
    }

    if ($temp <= 8) {
        return [
            'emoji' => '🥶',
            'summary' => "+{$temp}°C, прохладно",
            'advice' => Arr::random($this->coldAdvice),
        ];
    }

    return [
        'emoji' => match (true) {
            in_array($code, [0, 1], true) => '☀️',
            in_array($code, [2, 3], true) => '🌤',
            default => '🌤',
        },
        'summary' => match (true) {
            in_array($code, [0, 1], true) => "+{$temp}°C, солнечно",
            in_array($code, [2, 3], true) => "+{$temp}°C, облачно",
            default => "+{$temp}°C",
        },
        'advice' => Arr::random($this->goodWeatherAdvice),
    ];
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
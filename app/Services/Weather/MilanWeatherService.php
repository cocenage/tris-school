<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MilanWeatherService
{
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

    protected function formatWeather(int $temp, ?int $code, bool $shiftRain, int $rainProbability): array
    {
        if ($shiftRain) {
            return [
                'emoji' => '🌧',
                'summary' => "+{$temp}°C, возможен дождь",
                'advice' => '☂️ Не забудьте зонтик.',
            ];
        }

        if ($temp >= 30) {
            return [
                'emoji' => '☀️',
                'summary' => "+{$temp}°C, жарко",
                'advice' => '💧 Не забывайте пить воду.',
            ];
        }

        if (in_array($code, [0, 1], true)) {
            return [
                'emoji' => '☀️',
                'summary' => "+{$temp}°C, солнечно",
                'advice' => null,
            ];
        }

        if (in_array($code, [2, 3], true)) {
            return [
                'emoji' => '🌤',
                'summary' => "+{$temp}°C, облачно",
                'advice' => null,
            ];
        }

        return [
            'emoji' => '🌤',
            'summary' => "+{$temp}°C",
            'advice' => null,
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
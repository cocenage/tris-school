<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MilanWeatherService
{
    private const FORECAST_DAYS = 3;

    private const MORNING_TIMES = ['08:00', '13:00', '15:00'];

    protected int $shiftStartHour = 8;

    protected int $shiftEndHour = 17;

    public function today(
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): array {
        return $this->forecastForDate(CarbonImmutable::now($timezone), $latitude, $longitude, $timezone)
            ?? $this->fallback();
    }

    public function forDate(
        CarbonImmutable $date,
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): ?array {
        return $this->forecastForDate($date, $latitude, $longitude, $timezone);
    }

    public function supportsForecastDate(CarbonImmutable $date, string $timezone = 'Europe/Rome'): bool
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $target = $date->setTimezone($timezone)->startOfDay();

        return $target->greaterThanOrEqualTo($today)
            && $target->lessThanOrEqualTo($today->addDays(self::FORECAST_DAYS - 1));
    }

    public function morningForecast(
        CarbonImmutable $date,
        float $latitude = 45.4642,
        float $longitude = 9.1900,
        string $timezone = 'Europe/Rome',
    ): array {
        $target = $date->setTimezone($timezone)->startOfDay();
        $points = array_fill_keys(self::MORNING_TIMES, null);

        if (! $this->supportsForecastDate($target, $timezone)) {
            return $this->unavailableMorning($points, 'date_out_of_range');
        }

        $forecast = $this->requestForecast($latitude, $longitude, $timezone);
        if ($forecast['data'] === null) {
            return $this->unavailableMorning($points, $forecast['unavailable_reason']);
        }

        $hours = $this->hoursForDate($forecast['data'], $target);
        if ($hours === null) {
            Log::warning('WeatherAPI forecast did not contain a usable target day.');

            return $this->unavailableMorning($points, 'malformed_response');
        }

        foreach ($hours as $hour) {
            $timestamp = $hour['time_epoch'] ?? null;
            if (! is_numeric($timestamp)) {
                continue;
            }

            $localTime = CarbonImmutable::createFromTimestampUTC((int) $timestamp)->setTimezone($timezone);
            if ($localTime->toDateString() !== $target->toDateString()) {
                continue;
            }

            $key = $localTime->format('H:i');
            if (! array_key_exists($key, $points) || $points[$key] !== null) {
                continue;
            }

            $point = $this->mapHour($hour);
            if (count(array_filter($point, fn (mixed $value): bool => $value !== null)) > 0) {
                $points[$key] = $point;
            }
        }

        return [
            'points' => $points,
            'advice' => $this->morningAdvice($points),
            'unavailable_reason' => null,
        ];
    }

    private function forecastForDate(
        CarbonImmutable $date,
        float $latitude,
        float $longitude,
        string $timezone,
    ): ?array {
        $target = $date->setTimezone($timezone)->startOfDay();
        if (! $this->supportsForecastDate($target, $timezone)) {
            return null;
        }

        $forecast = $this->requestForecast($latitude, $longitude, $timezone);
        if ($forecast['data'] === null) {
            return null;
        }

        $hours = $this->hoursForDate($forecast['data'], $target);
        if ($hours === null) {
            Log::warning('WeatherAPI forecast did not contain a usable target day.');

            return null;
        }

        return $this->formatShiftWeather($hours, $target, $timezone);
    }

    /** @return array{data: array|null, unavailable_reason: string|null} */
    private function requestForecast(float $latitude, float $longitude, string $timezone): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Log::warning('WeatherAPI request skipped: invalid coordinates.');

            return ['data' => null, 'unavailable_reason' => 'invalid_coordinates'];
        }

        $key = config('services.weatherapi.key');
        if (! is_string($key) || trim($key) === '') {
            Log::warning('WeatherAPI request skipped: API key is not configured.');

            return ['data' => null, 'unavailable_reason' => 'missing_api_key'];
        }

        try {
            $response = Http::timeout(15)->get('https://api.weatherapi.com/v1/forecast.json', [
                'key' => $key,
                'q' => $latitude.','.$longitude,
                'days' => self::FORECAST_DAYS,
                'aqi' => 'no',
                'alerts' => 'no',
                'lang' => 'ru',
            ]);
        } catch (\Throwable $e) {
            Log::warning('WeatherAPI request failed.', [
                'exception' => class_basename($e),
            ]);

            return ['data' => null, 'unavailable_reason' => 'provider_unavailable'];
        }

        if (! $response->successful()) {
            $providerCode = data_get($response->json(), 'error.code');
            Log::warning('WeatherAPI returned an unsuccessful response.', [
                'status' => $response->status(),
                'provider_error_code' => is_numeric($providerCode) ? (int) $providerCode : null,
            ]);

            return ['data' => null, 'unavailable_reason' => 'provider_unavailable'];
        }

        $data = $response->json();
        if (! is_array($data) || ! is_array(data_get($data, 'forecast.forecastday'))) {
            Log::warning('WeatherAPI returned an unusable forecast response.');

            return ['data' => null, 'unavailable_reason' => 'malformed_response'];
        }

        return ['data' => $data, 'unavailable_reason' => null];
    }

    private function hoursForDate(array $data, CarbonImmutable $target): ?array
    {
        foreach (data_get($data, 'forecast.forecastday', []) as $day) {
            if (($day['date'] ?? null) !== $target->toDateString()) {
                continue;
            }

            return is_array($day['hour'] ?? null) ? $day['hour'] : null;
        }

        return null;
    }

    private function mapHour(array $hour): array
    {
        $condition = is_array($hour['condition'] ?? null) ? $hour['condition'] : [];
        $code = $this->nullableInteger($condition['code'] ?? null);
        [$fallbackCondition, $emoji] = $this->weatherCondition($code);
        $providerCondition = $condition['text'] ?? null;

        return [
            'condition' => is_string($providerCondition) && trim($providerCondition) !== ''
                ? trim($providerCondition)
                : $fallbackCondition,
            'emoji' => $emoji,
            'weather_code' => $code,
            'temperature_c' => $this->nullableFloat($hour['temp_c'] ?? null),
            'apparent_temperature_c' => $this->nullableFloat($hour['feelslike_c'] ?? null),
            'relative_humidity_pct' => $this->nullableInteger($hour['humidity'] ?? null),
            'wind_speed_kmh' => $this->nullableFloat($hour['wind_kph'] ?? null),
            'wind_direction' => $this->windDirection(
                $this->nullableFloat($hour['wind_degree'] ?? null),
                is_string($hour['wind_dir'] ?? null) ? $hour['wind_dir'] : null,
            ),
            'precipitation_probability_pct' => $this->nullableInteger($hour['chance_of_rain'] ?? null),
            'rain_mm' => $this->nullableFloat($hour['precip_mm'] ?? null),
        ];
    }

    private function unavailableMorning(array $points, string $reason): array
    {
        return [
            'points' => $points,
            'advice' => null,
            'unavailable_reason' => $reason,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function windDirection(?float $degrees, ?string $direction): ?string
    {
        if ($degrees !== null) {
            $directions = ['С', 'СВ', 'В', 'ЮВ', 'Ю', 'ЮЗ', 'З', 'СЗ'];
            $sector = (int) floor((fmod(($degrees + 360), 360) + 22.5) / 45) % 8;

            return $directions[$sector];
        }

        return match (strtoupper(trim((string) $direction))) {
            'N' => 'С', 'NNE', 'NE' => 'СВ', 'ENE', 'E' => 'В', 'ESE', 'SE' => 'ЮВ',
            'SSE', 'S' => 'Ю', 'SSW', 'SW' => 'ЮЗ', 'WSW', 'W' => 'З', 'WNW', 'NW', 'NNW' => 'СЗ',
            default => null,
        };
    }

    private function weatherCondition(?int $code): array
    {
        return match ($code) {
            1000 => ['Ясно', '☀️'],
            1003 => ['Переменная облачность', '🌤'],
            1006 => ['Облачно', '🌥'],
            1009 => ['Пасмурно', '☁️'],
            1030, 1135, 1147 => ['Туман', '🌫'],
            1063, 1150, 1153, 1168, 1171, 1180, 1183, 1186, 1189, 1192, 1195,
            1198, 1201, 1240, 1243, 1246 => ['Дождь', '🌧'],
            1066, 1072, 1114, 1117, 1210, 1213, 1216, 1219, 1222, 1225, 1255, 1258 => ['Снег', '❄️'],
            1069, 1072, 1168, 1171, 1198, 1201, 1204, 1207, 1249, 1252 => ['Снег с дождём', '🌨'],
            1087, 1273, 1276, 1279, 1282 => ['Гроза', '⛈'],
            1237, 1261, 1264 => ['Ледяные осадки', '🧊'],
            default => [null, null],
        };
    }

    private function morningAdvice(array $points): ?string
    {
        $thunder = [1087, 1273, 1276, 1279, 1282];
        $snowOrIce = [1066, 1069, 1072, 1114, 1117, 1168, 1171, 1198, 1201, 1204, 1207, 1210, 1213, 1216, 1219, 1222, 1225, 1237, 1249, 1252, 1255, 1258, 1261, 1264, 1279, 1282];
        $rain = [1063, 1069, 1072, 1150, 1153, 1168, 1171, 1180, 1183, 1186, 1189, 1192, 1195, 1198, 1201, 1204, 1207, 1240, 1243, 1246, 1249, 1252, 1273, 1276];
        $heavyRain = [1192, 1195, 1243, 1246, 1276];

        foreach ($points as $time => $point) {
            if ($point === null) {
                continue;
            }

            $code = $point['weather_code'];
            if ($code !== null && in_array($code, $thunder, true)) {
                return "⚠️ Прогноз указывает на грозу около {$time}; учитывайте это при перемещении между квартирами.";
            }

            if ($code !== null && in_array($code, $snowOrIce, true)) {
                return "⚠️ Прогноз снега или гололёда около {$time}; планируйте перемещения осторожно.";
            }

            if ($point['wind_speed_kmh'] !== null && $point['wind_speed_kmh'] >= 50) {
                return "⚠️ Около {$time} ожидается очень сильный ветер (от 50 км/ч); учитывайте условия на улице.";
            }

            if ($code !== null && in_array($code, $heavyRain, true)) {
                return "⚠️ Около {$time} прогнозируется сильный дождь; возможны задержки в пути.";
            }
            if ($point['rain_mm'] !== null && $point['rain_mm'] >= 4) {
                return "⚠️ Около {$time} прогнозируется интенсивный дождь; возможны задержки в пути.";
            }
            if ($code !== null && in_array($code, $rain, true)
                && $point['precipitation_probability_pct'] !== null
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

    protected function formatShiftWeather(array $hours, CarbonImmutable $target, string $timezone): array
    {
        $shiftTemps = [];
        $shiftCodes = [];
        $maxRainProbability = 0;
        $totalRain = 0.0;
        $maxWind = 0.0;
        $rainStartHour = null;

        foreach ($hours as $hour) {
            $timestamp = $hour['time_epoch'] ?? null;
            if (! is_numeric($timestamp)) {
                continue;
            }

            $localTime = CarbonImmutable::createFromTimestampUTC((int) $timestamp)->setTimezone($timezone);
            $hourOfDay = (int) $localTime->format('G');
            if ($localTime->toDateString() !== $target->toDateString()
                || $hourOfDay < $this->shiftStartHour || $hourOfDay > $this->shiftEndHour) {
                continue;
            }

            $temperature = $this->nullableFloat($hour['temp_c'] ?? null);
            if ($temperature !== null) {
                $shiftTemps[] = $temperature;
            }

            $rain = $this->nullableFloat($hour['precip_mm'] ?? null);
            $probability = $this->nullableInteger($hour['chance_of_rain'] ?? null);
            $wind = $this->nullableFloat($hour['wind_kph'] ?? null);
            if ($rain !== null) {
                $totalRain += $rain;
            }
            if ($probability !== null) {
                $maxRainProbability = max($maxRainProbability, $probability);
            }
            if ($wind !== null) {
                $maxWind = max($maxWind, $wind);
            }
            if ($rainStartHour === null && (($rain ?? 0) > 0 || ($probability ?? 0) >= 50)) {
                $rainStartHour = $hourOfDay;
            }

            $code = $this->nullableInteger(data_get($hour, 'condition.code'));
            if ($code !== null) {
                $shiftCodes[] = $code;
            }
        }

        if ($shiftTemps === []) {
            return $this->fallback();
        }

        return $this->buildResult(
            minTemp: (int) round(min($shiftTemps)),
            maxTemp: (int) round(max($shiftTemps)),
            mainCode: $this->mainWeatherCode($shiftCodes),
            maxRainProbability: $maxRainProbability,
            totalRain: $totalRain,
            maxWind: $maxWind,
            rainStartHour: $rainStartHour,
        );
    }

    protected function buildResult(
        int $minTemp,
        int $maxTemp,
        ?int $mainCode,
        int $maxRainProbability,
        float $totalRain,
        float $maxWind,
        ?int $rainStartHour,
    ): array {
        $tempText = $minTemp === $maxTemp
            ? sprintf('%+d°C', $maxTemp)
            : sprintf('%+d…%+d°C', $minTemp, $maxTemp);

        $hasRain = $totalRain > 0 || $maxRainProbability >= 50;
        $hasHeavyRain = $totalRain >= 4 || $maxRainProbability >= 75;
        $isHot = $maxTemp >= 30;
        $isColdMorning = $minTemp <= 8;
        $isWindy = $maxWind >= 30;
        $isIcy = in_array($mainCode, [1069, 1072, 1168, 1171, 1198, 1201, 1204, 1207, 1237, 1249, 1252, 1261, 1264], true);
        $isSnow = in_array($mainCode, [1066, 1114, 1117, 1210, 1213, 1216, 1219, 1222, 1225, 1255, 1258], true);

        if ($isIcy || $isSnow) {
            return [
                'emoji' => $isIcy ? '🧊' : '❄️',
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
            return ['emoji' => '☀️', 'summary' => "{$tempText}, жарко", 'advice' => null];
        }
        if ($isColdMorning) {
            return ['emoji' => '🥶', 'summary' => "{$tempText}, прохладно утром", 'advice' => null];
        }
        if (in_array($mainCode, [1000], true)) {
            return ['emoji' => '☀️', 'summary' => "{$tempText}, солнечно", 'advice' => null];
        }
        if (in_array($mainCode, [1003, 1006, 1009], true)) {
            return ['emoji' => '🌤', 'summary' => "{$tempText}, облачно", 'advice' => null];
        }

        return ['emoji' => '🌤', 'summary' => $tempText, 'advice' => null];
    }

    protected function mainWeatherCode(array $codes): ?int
    {
        if ($codes === []) {
            return null;
        }

        $priority = [
            1087, 1273, 1276, 1279, 1282,
            1069, 1072, 1168, 1171, 1198, 1201, 1204, 1207, 1237, 1249, 1252, 1261, 1264,
            1066, 1114, 1117, 1210, 1213, 1216, 1219, 1222, 1225, 1255, 1258,
            1192, 1195, 1243, 1246,
            1063, 1150, 1153, 1180, 1183, 1186, 1189, 1240,
            1030, 1135, 1147,
            1009, 1006, 1003, 1000,
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

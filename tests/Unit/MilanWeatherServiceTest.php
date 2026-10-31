<?php

use App\Services\Weather\MilanWeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('requests weather for supplied district coordinates and timezone', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'hourly' => [
            'time' => ['2026-09-18T08:00', '2026-09-18T17:00'],
            'temperature_2m' => [18, 24],
            'precipitation_probability' => [0, 10],
            'rain' => [0, 0],
            'weather_code' => [1, 1],
            'wind_speed_10m' => [5, 7],
        ],
    ], 200)]);

    $weather = app(MilanWeatherService::class)->today(45.45, 9.17, 'Europe/Rome');

    expect($weather['summary'])->toContain('+18…+24°C');
    Http::assertSent(fn (Request $request): bool => (float) $request['latitude'] === 45.45
        && (float) $request['longitude'] === 9.17
        && $request['timezone'] === 'Europe/Rome'
    );
});

it('returns the established unavailable fallback on provider failure', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response([], 503)]);

    expect(app(MilanWeatherService::class)->today(45.45, 9.17))->toBe([
        'emoji' => '🌤',
        'summary' => 'погода временно недоступна',
        'advice' => null,
    ]);
});

it('gives a concrete travel impact only for heavy afternoon rain', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => ['2026-10-07T08:00', '2026-10-07T13:00'],
        'temperature_2m' => [16, 18], 'precipitation_probability' => [0, 90],
        'rain' => [0, 5], 'weather_code' => [1, 65], 'wind_speed_10m' => [5, 8],
    ]])]);

    $weather = app(MilanWeatherService::class)->today();

    expect($weather['summary'])->toBe('+16…+18°C, после 13:00 сильный дождь')
        ->and($weather['advice'])->toBe('🌧 После обеда лучше заложить больше времени на дорогу между квартирами.')
        ->not->toContain('Возможны задержки из-за погоды');
});

it('omits operational warnings for light rain and normal weather', function (array $rain, array $probability, array $codes) {
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => ['2026-10-07T08:00', '2026-10-07T13:00'],
        'temperature_2m' => [16, 18], 'precipitation_probability' => $probability,
        'rain' => $rain, 'weather_code' => $codes, 'wind_speed_10m' => [5, 8],
    ]])]);

    expect(app(MilanWeatherService::class)->today()['advice'])->toBeNull();
})->with([
    [[0, 0.2], [0, 55], [1, 61]],
    [[0, 0], [0, 0], [1, 1]],
]);

it('uses concrete movement guidance for snow, ice and strong wind', function (int $code, int $wind, string $summary, string $advice) {
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => ['2026-10-07T08:00'],
        'temperature_2m' => [-2], 'precipitation_probability' => [0],
        'rain' => [0], 'weather_code' => [$code], 'wind_speed_10m' => [$wind],
    ]])]);

    $weather = app(MilanWeatherService::class)->today();

    expect($weather['summary'])->toBe($summary)
        ->and($weather['advice'])->toBe($advice);
})->with([
    [73, 5, '-2°C, снег', '❄️ На дорогу между квартирами лучше заложить дополнительное время.'],
    [66, 5, '-2°C, гололёд', '❄️ На дорогу между квартирами лучше заложить дополнительное время.'],
    [1, 35, '-2°C, сильный ветер', '💨 На улице сильный ветер — аккуратнее с балконами, окнами и перемещением между квартирами.'],
]);

it('returns morning points matched by exact unix timestamp with all requested measures', function () {
    $timestamps = collect(['15:00', '08:00', '13:00'])
        ->map(fn (string $time): int => CarbonImmutable::parse('2026-10-31 '.$time, 'Europe/Rome')->getTimestamp())
        ->all();

    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => $timestamps,
        'temperature_2m' => [16.2, 12.4, 18.0],
        'apparent_temperature' => [14.1, 10.2, 17.0],
        'relative_humidity_2m' => [75, 82, 60],
        'precipitation_probability' => [65, 20, 10],
        'weather_code' => [61, 2, 0],
        'wind_speed_10m' => [19.4, 8.2, 12.1],
        'wind_direction_10m' => [225, 315, 270],
        'rain' => [1.2, 0, 0],
    ]])]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse('2026-10-31', 'Europe/Rome'),
        45.45,
        9.17,
    );

    expect($forecast['points']['08:00'])->toMatchArray([
        'condition' => 'Облачно', 'emoji' => '🌥', 'temperature_c' => 12.4,
        'apparent_temperature_c' => 10.2, 'relative_humidity_pct' => 82,
        'wind_speed_kmh' => 8.2, 'wind_direction' => 'СЗ', 'precipitation_probability_pct' => 20,
    ])
        ->and($forecast['points']['13:00'])->toMatchArray([
            'condition' => 'Ясно', 'emoji' => '☀️', 'temperature_c' => 18.0,
            'wind_direction' => 'З', 'precipitation_probability_pct' => 10,
        ])
        ->and($forecast['points']['15:00'])->toMatchArray([
            'condition' => 'Дождь', 'emoji' => '🌧', 'wind_direction' => 'ЮЗ',
            'precipitation_probability_pct' => 65,
        ]);

    Http::assertSent(fn (Request $request): bool => $request['timeformat'] === 'unixtime'
        && $request['timezone'] === 'Europe/Rome'
        && $request['start_date'] === '2026-10-31'
        && $request['end_date'] === '2026-10-31'
        && str_contains($request['hourly'], 'apparent_temperature')
        && str_contains($request['hourly'], 'relative_humidity_2m')
        && str_contains($request['hourly'], 'wind_direction_10m')
    );
});

it('keeps missing local time slots and hourly measures unavailable instead of replacing them with zero', function () {
    $timestamp = CarbonImmutable::parse('2026-10-31 13:00', 'Europe/Rome')->getTimestamp();
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => [$timestamp],
        'temperature_2m' => [17],
        'relative_humidity_2m' => '80',
        'weather_code' => [1],
    ]])]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse('2026-10-31', 'Europe/Rome'), 45.45, 9.17,
    );

    expect($forecast['points']['08:00'])->toBeNull()
        ->and($forecast['points']['13:00']['temperature_c'])->toBe(17.0)
        ->and($forecast['points']['13:00']['relative_humidity_pct'])->toBeNull()
        ->and($forecast['points']['13:00']['wind_speed_kmh'])->toBeNull()
        ->and($forecast['points']['15:00'])->toBeNull();
});

it('maps unix timestamps to the three Rome forecast hours across daylight-saving changes', function (string $date, string $utc08, string $utc13, string $utc15) {
    $times = array_map(
        fn (string $time): int => CarbonImmutable::parse($time, 'UTC')->getTimestamp(),
        [$utc15, $utc08, $utc13],
    );
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => $times,
        'temperature_2m' => [15, 8, 13],
        'apparent_temperature' => [14, 7, 12],
        'relative_humidity_2m' => [60, 70, 65],
        'precipitation_probability' => [0, 0, 0],
        'weather_code' => [0, 0, 0],
        'wind_speed_10m' => [5, 5, 5],
        'wind_direction_10m' => [0, 0, 0],
    ]])]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse($date, 'Europe/Rome'), 45.45, 9.17,
    );

    expect($forecast['points']['08:00']['temperature_c'])->toBe(8.0)
        ->and($forecast['points']['13:00']['temperature_c'])->toBe(13.0)
        ->and($forecast['points']['15:00']['temperature_c'])->toBe(15.0);
})->with([
    'spring forward' => ['2026-03-29', '2026-03-29 06:00', '2026-03-29 11:00', '2026-03-29 13:00'],
    'fall back' => ['2026-10-25', '2026-10-25 07:00', '2026-10-25 12:00', '2026-10-25 14:00'],
]);

it('returns unavailable points for every requested hour when the weather provider fails', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response([], 503)]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse('2026-10-31', 'Europe/Rome'), 45.45, 9.17,
    );

    expect($forecast['points'])->toBe([
        '08:00' => null,
        '13:00' => null,
        '15:00' => null,
    ])->and($forecast['advice'])->toBeNull();
});

it('adds practical advice only when hourly data crosses a severe weather signal', function () {
    $times = collect(['08:00', '13:00', '15:00'])
        ->map(fn (string $time): int => CarbonImmutable::parse('2026-10-31 '.$time, 'Europe/Rome')->getTimestamp())
        ->all();
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => $times,
        'temperature_2m' => [16, 18, 17],
        'apparent_temperature' => [16, 18, 17],
        'relative_humidity_2m' => [55, 60, 65],
        'precipitation_probability' => [0, 10, 20],
        'weather_code' => [0, 95, 2],
        'wind_speed_10m' => [10, 12, 14],
        'wind_direction_10m' => [0, 90, 180],
        'rain' => [0, 0, 0],
    ]])]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse('2026-10-31', 'Europe/Rome'), 45.45, 9.17,
    );

    expect($forecast['advice'])->toContain('грозу около 13:00');
});

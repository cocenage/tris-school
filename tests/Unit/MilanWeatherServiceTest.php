<?php

use App\Services\Weather\MilanWeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function weatherApiUnitHour(string $time, array $overrides = []): array
{
    $hour = [
        'time' => $time,
        'time_epoch' => CarbonImmutable::parse($time, 'Europe/Rome')->getTimestamp(),
        'temp_c' => 16,
        'feelslike_c' => 15,
        'humidity' => 60,
        'wind_kph' => 10,
        'wind_dir' => 'NW',
        'wind_degree' => 315,
        'chance_of_rain' => 0,
        'precip_mm' => 0,
        'condition' => ['text' => 'Ясно', 'code' => 1000],
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($hour[$key]);
        } else {
            $hour[$key] = $value;
        }
    }

    return $hour;
}

function weatherApiUnitResponse(string $date, array $hours): array
{
    return ['forecast' => ['forecastday' => [['date' => $date, 'hour' => $hours]]]];
}

beforeEach(function () {
    config(['services.weatherapi.key' => 'unit-test-key']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('keeps the today and forDate result contracts while requesting the WeatherAPI forecast', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::sequence()
        ->push(weatherApiUnitResponse('2026-10-31', [
            weatherApiUnitHour('2026-10-31 08:00', ['temp_c' => 18]),
            weatherApiUnitHour('2026-10-31 17:00', ['temp_c' => 24]),
        ]))
        ->push(weatherApiUnitResponse('2026-11-01', [
            weatherApiUnitHour('2026-11-01 08:00', ['temp_c' => 10]),
            weatherApiUnitHour('2026-11-01 17:00', ['temp_c' => 12]),
        ]))]);

    $service = app(MilanWeatherService::class);
    $today = $service->today(45.45, 9.17);
    $tomorrow = $service->forDate(CarbonImmutable::parse('2026-11-01', 'Europe/Rome'), 45.45, 9.17);

    expect($today)->toHaveKeys(['emoji', 'summary', 'advice'])
        ->and($today['summary'])->toContain('+18…+24°C')
        ->and($tomorrow)->toMatchArray([
            'emoji' => '☀️', 'summary' => '+10…+12°C, солнечно', 'advice' => null,
        ]);

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api.weatherapi.com'
        && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/v1/forecast.json')
        && $request['key'] === 'unit-test-key'
        && $request['q'] === '45.45,9.17'
        && (int) $request['days'] === 3
        && $request['aqi'] === 'no'
        && $request['alerts'] === 'no'
        && $request['lang'] === 'ru');
    Http::assertSentCount(2);
});

it('returns the established unavailable fallback on provider failure', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response([], 503)]);

    expect(app(MilanWeatherService::class)->today())->toBe([
        'emoji' => '🌤',
        'summary' => 'погода временно недоступна',
        'advice' => null,
    ]);
});

it('treats a provider timeout as unavailable without retrying the request', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    $attempts = 0;
    Http::fake(['api.weatherapi.com/*' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('timeout');
    }]);

    expect(app(MilanWeatherService::class)->today()['summary'])->toBe('погода временно недоступна');
    expect($attempts)->toBe(1);
});

it('gives a concrete travel impact only for heavy afternoon rain', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', [
        weatherApiUnitHour('2026-10-31 08:00', ['temp_c' => 16]),
        weatherApiUnitHour('2026-10-31 13:00', [
            'temp_c' => 18,
            'chance_of_rain' => 90,
            'precip_mm' => 5,
            'condition' => ['text' => 'Сильный дождь', 'code' => 1195],
        ]),
    ]))]);

    $weather = app(MilanWeatherService::class)->today();

    expect($weather['summary'])->toBe('+16…+18°C, после 13:00 сильный дождь')
        ->and($weather['advice'])->toBe('🌧 После обеда лучше заложить больше времени на дорогу между квартирами.');
});

it('keeps light rain from generating an operational warning', function (array $hour) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', [$hour]))]);

    expect(app(MilanWeatherService::class)->today()['advice'])->toBeNull();
})->with([
    'light rain' => [weatherApiUnitHour('2026-10-31 08:00', [
        'chance_of_rain' => 55,
        'precip_mm' => 0.2,
        'condition' => ['text' => 'Небольшой дождь', 'code' => 1183],
    ])],
    'clear weather' => [weatherApiUnitHour('2026-10-31 08:00')],
]);

it('preserves concrete travel advice for snow, sleet and strong wind', function (array $hour, string $summary, string $advice) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', [$hour]))]);

    $weather = app(MilanWeatherService::class)->today();

    expect($weather['summary'])->toBe($summary)->and($weather['advice'])->toBe($advice);
})->with([
    'snow' => [weatherApiUnitHour('2026-10-31 08:00', [
        'temp_c' => -2, 'condition' => ['text' => 'Снег', 'code' => 1213],
    ]), '-2°C, снег', '❄️ На дорогу между квартирами лучше заложить дополнительное время.'],
    'sleet' => [weatherApiUnitHour('2026-10-31 08:00', [
        'temp_c' => -2, 'condition' => ['text' => 'Мокрый снег', 'code' => 1204],
    ]), '-2°C, гололёд', '❄️ На дорогу между квартирами лучше заложить дополнительное время.'],
    'wind' => [weatherApiUnitHour('2026-10-31 08:00', [
        'temp_c' => -2, 'wind_kph' => 35, 'condition' => ['text' => 'Ясно', 'code' => 1000],
    ]), '-2°C, сильный ветер', '💨 На улице сильный ветер — аккуратнее с балконами, окнами и перемещением между квартирами.'],
]);

it('maps shuffled WeatherAPI hours by Unix epoch and returns every available measure', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    $hours = [
        weatherApiUnitHour('2026-10-31 15:00', [
            'temp_c' => 16.2, 'feelslike_c' => 14.1, 'humidity' => 75, 'wind_kph' => 19.4,
            'wind_degree' => 225, 'chance_of_rain' => 65, 'precip_mm' => 1.2,
            'condition' => ['text' => 'Дождь', 'code' => 1183],
        ]),
        weatherApiUnitHour('2026-10-31 08:00', [
            'temp_c' => 12.4, 'feelslike_c' => 10.2, 'humidity' => 82, 'wind_kph' => 8.2,
            'wind_degree' => 315, 'chance_of_rain' => 20,
            'condition' => ['text' => 'Облачно', 'code' => 1006],
        ]),
        weatherApiUnitHour('2026-10-31 13:00', [
            'temp_c' => 18, 'feelslike_c' => 17, 'humidity' => 60, 'wind_kph' => 12.1,
            'wind_degree' => 270, 'chance_of_rain' => 10,
            'condition' => ['text' => 'Ясно', 'code' => 1000],
        ]),
    ];
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', $hours))]);

    $forecast = app(MilanWeatherService::class)->morningForecast(
        CarbonImmutable::parse('2026-10-31', 'Europe/Rome'), 45.45, 9.17,
    );

    expect($forecast['points']['08:00'])->toMatchArray([
        'condition' => 'Облачно', 'temperature_c' => 12.4, 'apparent_temperature_c' => 10.2,
        'relative_humidity_pct' => 82, 'wind_speed_kmh' => 8.2, 'wind_direction' => 'СЗ',
        'precipitation_probability_pct' => 20,
    ])
        ->and($forecast['points']['13:00']['condition'])->toBe('Ясно')
        ->and($forecast['points']['15:00'])->toMatchArray([
            'condition' => 'Дождь', 'wind_direction' => 'ЮЗ', 'rain_mm' => 1.2,
        ]);
});

it('keeps missing WeatherAPI fields unavailable instead of replacing them with zero', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    $hour = weatherApiUnitHour('2026-10-31 13:00', [
        'temp_c' => 17,
        'humidity' => null,
        'wind_kph' => null,
        'wind_degree' => null,
        'wind_dir' => null,
        'chance_of_rain' => null,
        'precip_mm' => null,
    ]);
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', [$hour]))]);

    $forecast = app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse('2026-10-31', 'Europe/Rome'));

    expect($forecast['points']['08:00'])->toBeNull()
        ->and($forecast['points']['13:00'])->toMatchArray([
            'temperature_c' => 17.0, 'relative_humidity_pct' => null,
            'wind_speed_kmh' => null, 'wind_direction' => null, 'precipitation_probability_pct' => null,
        ])
        ->and($forecast['points']['15:00'])->toBeNull();
});

it('maps WeatherAPI Unix timestamps to Rome morning hours on both DST transitions', function (string $date, string $utc08, string $utc13, string $utc15) {
    $today = CarbonImmutable::parse($date.' 06:00', 'Europe/Rome');
    CarbonImmutable::setTestNow($today);
    $hours = collect([$utc15, $utc08, $utc13])->values()->map(fn (string $utc, int $index): array => [
        'time_epoch' => CarbonImmutable::parse($utc, 'UTC')->getTimestamp(),
        'temp_c' => [15, 8, 13][$index],
        'condition' => ['text' => 'Ясно', 'code' => 1000],
    ])->all();
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse($date, $hours))]);

    $forecast = app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse($date, 'Europe/Rome'));

    expect($forecast['points']['08:00']['temperature_c'])->toBe(8.0)
        ->and($forecast['points']['13:00']['temperature_c'])->toBe(13.0)
        ->and($forecast['points']['15:00']['temperature_c'])->toBe(15.0);
})->with([
    'spring forward' => ['2026-03-29', '2026-03-29 06:00', '2026-03-29 11:00', '2026-03-29 13:00'],
    'fall back' => ['2026-10-25', '2026-10-25 07:00', '2026-10-25 12:00', '2026-10-25 14:00'],
]);

it('does not call WeatherAPI without a key or for a date outside the Free forecast window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake();

    config(['services.weatherapi.key' => null]);
    $withoutKey = app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse('2026-10-31', 'Europe/Rome'));
    expect($withoutKey['unavailable_reason'])->toBe('missing_api_key');
    Http::assertNothingSent();

    config(['services.weatherapi.key' => 'unit-test-key']);
    $outOfRange = app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse('2026-11-03', 'Europe/Rome'));
    expect($outOfRange['unavailable_reason'])->toBe('date_out_of_range')
        ->and($outOfRange['points'])->toBe(['08:00' => null, '13:00' => null, '15:00' => null]);
    Http::assertNothingSent();
});

it('treats authentication, quota, rate-limit and malformed responses as unavailable forecasts', function (int $status, array|string $body) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response($body, $status)]);

    $forecast = app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse('2026-10-31', 'Europe/Rome'));

    expect($forecast['points'])->toBe(['08:00' => null, '13:00' => null, '15:00' => null])
        ->and($forecast['advice'])->toBeNull();
})->with([
    'invalid key' => [401, ['error' => ['code' => 2006, 'message' => 'invalid key']]],
    'quota exhausted' => [403, ['error' => ['code' => 2007, 'message' => 'quota exceeded']]],
    'rate limited' => [429, ['error' => ['code' => 2007, 'message' => 'rate limited']]],
    'malformed JSON' => [200, 'not-json'],
    'missing forecast structure' => [200, ['forecast' => ['forecastday' => 'invalid']]],
]);

it('returns null for historical and out-of-range forDate requests', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake();

    expect(app(MilanWeatherService::class)->forDate(CarbonImmutable::parse('2026-10-30', 'Europe/Rome')))->toBeNull()
        ->and(app(MilanWeatherService::class)->forDate(CarbonImmutable::parse('2026-11-03', 'Europe/Rome')))->toBeNull();
    Http::assertNothingSent();
});

it('returns a severe weather warning only when forecast fields support it', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response(weatherApiUnitResponse('2026-10-31', [
        weatherApiUnitHour('2026-10-31 08:00'),
        weatherApiUnitHour('2026-10-31 13:00', ['condition' => ['text' => 'Гроза', 'code' => 1276]]),
        weatherApiUnitHour('2026-10-31 15:00'),
    ]))]);

    expect(app(MilanWeatherService::class)->morningForecast(CarbonImmutable::parse('2026-10-31', 'Europe/Rome'))['advice'])
        ->toContain('грозу около 13:00');
});

<?php

use App\Services\Weather\MilanWeatherService;
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

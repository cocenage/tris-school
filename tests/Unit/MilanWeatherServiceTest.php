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

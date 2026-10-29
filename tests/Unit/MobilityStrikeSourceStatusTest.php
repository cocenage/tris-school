<?php

use App\Services\Mobility\MobilityStrikeSourceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();
});

it('distinguishes a successful empty source, events, unavailable and unrecognized structure', function (): void {
    app(MobilityStrikeSourceStatus::class)->recordRun([
        'mit' => ['status' => 'checked_empty', 'event_count' => 0],
        'atm' => ['status' => 'events_found', 'event_count' => 1],
        'trenord_news' => ['status' => 'unavailable', 'issue' => 'http_503'],
        'trenord_strikes' => ['status' => 'unrecognized'],
    ]);

    $report = app(MobilityStrikeSourceStatus::class)->forReport();

    expect($report['state'])->toBe('partial')
        ->and($report['sources']['mit']['available'])->toBeTrue()
        ->and($report['sources']['mit']['status'])->toBe('checked_empty')
        ->and($report['sources']['atm']['event_count'])->toBe(1)
        ->and($report['sources']['trenord_news']['status'])->toBe('unavailable')
        ->and($report['sources']['trenord_strikes']['status'])->toBe('unrecognized')
        ->and($report['sources']['mit']['last_successful_at'])->not->toBeNull();
});

it('retains the last successful timestamp when a later source check fails', function (): void {
    $status = app(MobilityStrikeSourceStatus::class);
    $firstCheck = CarbonImmutable::parse('2026-10-29 08:00:00', 'Europe/Rome');
    $secondCheck = $firstCheck->addHour();

    $status->recordRun(['atm' => ['status' => 'checked_empty']], $firstCheck);
    $status->recordRun(['atm' => ['status' => 'unavailable', 'issue' => 'timeout']], $secondCheck);

    $source = $status->forReport()['sources']['atm'];
    expect($source['status'])->toBe('unavailable')
        ->and($source['available'])->toBeFalse()
        ->and($source['last_successful_at'])->toBe($firstCheck->toIso8601String())
        ->and($source['issue'])->toBe('timeout');
});

it('keeps boolean status input compatible with existing callers', function (): void {
    app(MobilityStrikeSourceStatus::class)->recordRun(['mit' => true, 'atm' => false]);

    $report = app(MobilityStrikeSourceStatus::class)->forReport();
    expect($report['sources']['mit']['status'])->toBe('checked')
        ->and($report['sources']['atm']['status'])->toBe('unavailable')
        ->and($report['state'])->toBe('partial');
});

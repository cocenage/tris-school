<?php

use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
    Schema::create('antigoccia_applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('applied_on');
    });
    Schema::create('antigoccia_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date');
        $table->json('answers');
    });
    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date')->nullable();
        $table->string('status');
    });
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23', 'Europe/Rome'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
});

it('moves coating duration, registered cleaning count and all four surface scores into an admin analytics report', function (): void {
    DB::table('antigoccia_applications')->insert([
        'apartment_id' => 10, 'applied_on' => '2026-11-10',
    ]);
    $blank = collect(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES)
        ->mapWithKeys(fn ($label, $key) => [$key => ['dirt' => 0, 'removal' => null, 'remaining' => 'no']])->all();
    $first = $blank;
    $first['mirrors'] = ['dirt' => 2, 'removal' => 1, 'remaining' => 'no'];
    $second = $blank;
    $second['mirrors'] = ['dirt' => 2, 'removal' => 2, 'remaining' => 'yes'];

    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-11', 'answers' => json_encode($first)],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-15', 'answers' => json_encode($second)],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-12', 'answers' => json_encode($second)],
    ]);
    DB::table('control_responses')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-16', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-17', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-17', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-18', 'status' => 'draft'],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-21', 'status' => 'sent'],
    ]);

    $apartment = new Apartment();
    $apartment->id = 10;
    $stats = app(AntigocciaAnalytics::class)->forApartment($apartment);

    expect($stats['days_since_application'])->toBe(13)
        ->and($stats['recorded_cleaning_days'])->toBe(2)
        ->and($stats['last_control_date'])->toBe('2026-11-15')
        ->and($stats['report_count'])->toBe(2)
        ->and($stats['surfaces'])->toHaveCount(4)
        ->and($stats['surfaces']['mirrors']['total'])->toBe(2)
        ->and($stats['surfaces']['mirrors']['difficult'])->toBe(2)
        ->and($stats['surfaces']['mirrors']['failed'])->toBe(1)
        ->and($stats['surfaces']['mirrors']['remaining_count'])->toBe(1)
        ->and($stats['surfaces']['mirrors']['first_issue_report_number'])->toBe(1);
});

it('counts new four-way removal choices without reinterpreting legacy history', function (): void {
    $noDirt = collect(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES)
        ->mapWithKeys(fn ($label, $key) => [$key => ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no']])->all();

    $legacy = $noDirt;
    $legacy['mirrors'] = ['dirt' => 1, 'removal' => 1, 'remaining' => 'no'];
    $medium = $noDirt;
    $medium['mirrors'] = ['dirt' => 2, 'removal' => 'medium', 'remaining' => 'no'];
    $hard = $noDirt;
    $hard['mirrors'] = ['dirt' => 0, 'removal' => 'hard', 'remaining' => 'no'];

    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-12', 'answers' => json_encode($legacy)],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-15', 'answers' => json_encode($medium)],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-17', 'answers' => json_encode($hard)],
    ]);

    $apartment = new Apartment();
    $apartment->id = 10;
    $stats = app(AntigocciaAnalytics::class)->forApartment($apartment);
    $mirrors = $stats['surfaces']['mirrors'];

    expect($mirrors['total'])->toBe(3)
        ->and($mirrors['difficult'])->toBe(3)
        ->and($mirrors['failed'])->toBe(1)
        ->and($mirrors['remaining_count'])->toBe(0)
        ->and(collect($mirrors['recent'])->pluck('score')->all())->toBe([1, 'medium', 'hard']);
});

it('has empty stats for a never treated apartment rather than inventing dates', function (): void {
    $apartment = new Apartment();
    $apartment->id = 10;

    $stats = app(AntigocciaAnalytics::class)->forApartment($apartment);

    expect($stats['application'])->toBeNull()
        ->and($stats['days_since_application'])->toBeNull()
        ->and($stats['recorded_cleaning_days'])->toBeNull()
        ->and($stats['report_count'])->toBe(0);
});

it('does not expose admin stats to anonymous users', function (): void {
    expect(\App\Filament\Pages\AntigocciaStatistics::canAccess())->toBeFalse();
});

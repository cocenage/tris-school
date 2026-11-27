<?php

use App\Models\AntigocciaApplication;
use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaCleaningStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('antigoccia_reports');
    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date')->nullable();
        $table->string('status');
    });
    Schema::create('antigoccia_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date');
    });
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23', 'Europe/Rome'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('antigoccia_reports');
});

it('counts distinct recorded cleaning dates since the most recent ANTIGOCCIA control, not survey count', function (): void {
    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-10'],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-18'],
    ]);
    DB::table('control_responses')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-10', 'status' => 'sent'], // anchor, excluded
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-11', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-11', 'status' => 'sent'], // same cleaning day
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-12', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-13', 'status' => 'draft'], // not confirmed
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-24', 'status' => 'sent'], // future
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-14', 'status' => 'sent'], // other apartment
    ]);

    $apartment = new Apartment();
    $apartment->id = 10;
    $stats = app(AntigocciaCleaningStatistics::class)->forApartment($apartment, null);
    expect($stats)->toBe(['recorded_cleaning_days' => 2, 'last_control_date' => '2026-11-10']);
});

it('starts counting from the recorded coating date when there was no ANTIGOCCIA control', function (): void {
    DB::table('control_responses')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-10', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-12', 'status' => 'sent'],
    ]);
    $application = new AntigocciaApplication(['apartment_id' => 10, 'applied_on' => '2026-11-11']);
    $apartment = new Apartment();
    $apartment->id = 10;

    expect(app(AntigocciaCleaningStatistics::class)->forApartment($apartment, $application))
        ->toBe(['recorded_cleaning_days' => 1, 'last_control_date' => null]);
});

it('shows unavailable rather than inventing a count without a coating or previous control date', function (): void {
    $apartment = new Apartment();
    $apartment->id = 10;

    expect(app(AntigocciaCleaningStatistics::class)->forApartment($apartment, null))
        ->toBe(['recorded_cleaning_days' => null, 'last_control_date' => null]);
});


it('finds the latest earlier recorded cleaning day across both sources and never invents a gap', function (): void {
    DB::table('control_responses')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-10', 'status' => 'sent'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-16', 'status' => 'draft'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-20', 'status' => 'sent'],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-14', 'status' => 'sent'],
    ]);
    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-13'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-17'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-17'],
    ]);

    $statistics = app(AntigocciaCleaningStatistics::class);
    expect($statistics->daysSincePreviousRecordedCleaning(10, '2026-11-17'))->toBe(4)
        ->and($statistics->daysSincePreviousRecordedCleaning(10, '2026-11-12'))->toBe(2)
        ->and($statistics->daysSincePreviousRecordedCleaning(10, '2026-11-10'))->toBeNull()
        ->and($statistics->daysSincePreviousRecordedCleaning(12, '2026-11-17'))->toBeNull();
});

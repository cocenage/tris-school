<?php

use App\Services\Antigoccia\AntigocciaEffectiveness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
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
        $table->unsignedInteger('days_since_previous_cleaning')->nullable();
        $table->json('answers');
    });

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25', 'Europe/Rome'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
});

function reportForEffectiveness(
    int $apartment,
    string $date,
    ?int $daysSincePreviousCleaning,
    array $answers,
): void {
    DB::table('antigoccia_reports')->insert([
        'apartment_id' => $apartment,
        'cleaning_date' => $date,
        'days_since_previous_cleaning' => $daysSincePreviousCleaning,
        'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
    ]);
}

it('measures post-treatment outcomes without counting an unsoiled surface as easy removal', function (): void {
    DB::table('antigoccia_applications')->insert([
        ['apartment_id' => 10, 'applied_on' => '2026-10-01'],
        ['apartment_id' => 10, 'applied_on' => '2026-11-01'],
    ]);

    reportForEffectiveness(10, '2026-09-30', 4, [
        'mirrors' => ['dirt' => 2, 'removal' => 'hard', 'remaining' => 'yes'],
    ]); // Before application: excluded.

    reportForEffectiveness(10, '2026-10-02', 1, [
        'mirrors' => ['dirt' => 1, 'removal' => 'easy', 'remaining' => 'no'],
        'shower_outside' => ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no'],
    ]);

    reportForEffectiveness(10, '2026-10-15', 10, [
        'mirrors' => ['dirt' => 2, 'removal' => 'hard', 'remaining' => 'yes'],
        'shower_outside' => ['dirt' => 2, 'removal' => 'medium', 'remaining' => 'yes'],
    ]);

    reportForEffectiveness(10, '2026-11-03', 3, [
        'mirrors' => ['dirt' => 1, 'removal' => 'no_dirt', 'remaining' => 'no'],
        'shower_outside' => ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no'],
    ]); // After reapplication. Contradictory removal is NOT considered easy.

    reportForEffectiveness(10, '2026-11-08', null, [
        'mirrors' => ['dirt' => 1, 'removal' => 'easy', 'remaining' => 'yes'],
    ]);

    reportForEffectiveness(11, '2026-11-09', 3, [
        'mirrors' => ['dirt' => 2, 'removal' => 'hard', 'remaining' => 'yes'],
    ]); // Another apartment, no recorded application.

    reportForEffectiveness(10, '2026-11-27', 3, [
        'mirrors' => ['dirt' => 2, 'removal' => 'hard', 'remaining' => 'yes'],
    ]); // Future: excluded.

    $report = app(AntigocciaEffectiveness::class)->summarize(90);
    $overall = $report['overall'];

    expect($report['apartment_count'])->toBe(1)
        ->and($report['unlinked_reports'])->toBe(2)
        ->and($overall['reports'])->toBe(4)
        ->and($overall['residual_observations'])->toBe(7)
        ->and($overall['residual_yes'])->toBe(3)
        ->and($overall['residual_rate'])->toBe(43)
        ->and($overall['dirty_observations'])->toBe(5)
        ->and($overall['evaluable_removal'])->toBe(4)
        ->and($overall['easy'])->toBe(2)
        ->and($overall['difficult'])->toBe(2)
        ->and($overall['easy_rate'])->toBe(50)
        ->and($overall['difficult_rate'])->toBe(50)
        ->and($overall['without_visible_dirt'])->toBe(2)
        ->and($overall['inconsistent'])->toBe(1)
        ->and($report['ages']['first_week']['reports'])->toBe(3)
        ->and($report['ages']['first_month']['reports'])->toBe(1)
        ->and($report['gaps']['short']['reports'])->toBe(1)
        ->and($report['gaps']['medium']['reports'])->toBe(1)
        ->and($report['gaps']['long']['reports'])->toBe(1)
        ->and($report['gaps']['unknown']['reports'])->toBe(1)
        ->and($report['surfaces']['mirrors']['residual_rate'])->toBe(50)
        ->and($report['surfaces']['mirrors']['easy_rate'])->toBe(67);

    $recent = app(AntigocciaEffectiveness::class)->summarize(30);
    expect($recent['overall']['reports'])->toBe(2)
        ->and($recent['overall']['evaluable_removal'])->toBe(1);

    $perApartment = app(AntigocciaEffectiveness::class)->summarize(90, 10);
    expect($perApartment['overall']['reports'])->toBe(4)
        ->and($perApartment['unlinked_reports'])->toBe(1);
});

it('shows unavailable rates instead of invented zero-percent results', function (): void {
    $result = app(AntigocciaEffectiveness::class)->summarize(90);

    expect($result['overall']['reports'])->toBe(0)
        ->and($result['overall']['residual_rate'])->toBeNull()
        ->and($result['overall']['easy_rate'])->toBeNull()
        ->and($result['overall']['difficult_rate'])->toBeNull()
        ->and($result['ages']['later']['residual_rate'])->toBeNull()
        ->and($result['gaps']['unknown']['residual_rate'])->toBeNull();

    DB::table('antigoccia_applications')->insert(['apartment_id' => 20, 'applied_on' => '2026-11-10']);
    reportForEffectiveness(20, '2026-11-11', 1, [
        'mirrors' => ['dirt' => 0, 'removal' => 'no_dirt', 'remaining' => 'no'],
    ]);

    $treated = app(AntigocciaEffectiveness::class)->summarize(90);
    expect($treated['overall']['residual_rate'])->toBe(0)
        ->and($treated['overall']['easy_rate'])->toBeNull()
        ->and($treated['overall']['evaluable_removal'])->toBe(0);
});

it('accepts numeric legacy removal answers as legacy difficulty, never as no dirt', function (): void {
    DB::table('antigoccia_applications')->insert(['apartment_id' => 20, 'applied_on' => '2026-10-01']);
    reportForEffectiveness(20, '2026-10-10', 2, [
        'mirrors' => ['dirt' => 1, 'removal' => 0, 'remaining' => 'no'],
        'shower_outside' => ['dirt' => 1, 'removal' => 1, 'remaining' => 'no'],
        'shower_inside' => ['dirt' => 2, 'removal' => 2, 'remaining' => 'yes'],
    ]);

    $overall = app(AntigocciaEffectiveness::class)->summarize(0)['overall'];
    expect($overall['evaluable_removal'])->toBe(3)
        ->and($overall['easy'])->toBe(1)
        ->and($overall['difficult'])->toBe(2)
        ->and($overall['without_visible_dirt'])->toBe(0)
        ->and($overall['residual_rate'])->toBe(33);
});

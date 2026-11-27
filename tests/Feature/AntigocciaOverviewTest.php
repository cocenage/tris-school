<?php

use App\Services\Antigoccia\AntigocciaOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
    Schema::dropIfExists('apartments');
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('address')->nullable();
    });
    Schema::create('antigoccia_applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('applied_on');
    });
    Schema::create('antigoccia_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date');
        $table->boolean('remaining')->default(false);
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('cleaner_name')->default('Сотрудник');
        $table->timestamps();
    });
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23', 'Europe/Rome'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('antigoccia_reports');
    Schema::dropIfExists('antigoccia_applications');
    Schema::dropIfExists('apartments');
});

it('opens with useful totals and a limited list of tracked apartments only', function (): void {
    DB::table('apartments')->insert([
        ['id' => 10, 'name' => 'Aires 20', 'address' => 'Via Aires, 20'],
        ['id' => 11, 'name' => 'Binda 33', 'address' => 'Via Binda, 33'],
        ['id' => 12, 'name' => 'Another place', 'address' => 'Via Lontana, 3'],
    ]);
    DB::table('antigoccia_applications')->insert(['apartment_id' => 10, 'applied_on' => '2026-11-10']);
    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-11', 'remaining' => false],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-15', 'remaining' => true],
        ['apartment_id' => 11, 'cleaning_date' => '2026-08-10', 'remaining' => false],
    ]);

    $service = app(AntigocciaOverview::class);
    expect($service->totals())->toBe([
        'treated_apartments' => 1,
        'reports' => 3,
        'with_contamination' => 1,
        'last_30_days' => 2,
    ]);
    $apartments = $service->apartmentQuery()->get();
    expect($apartments->pluck('id')->all())->toBe([10, 11]);
    $rows = $service->rowsForApartments($apartments);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['reports'])->toBe(2)
        ->and($rows[0]['issues'])->toBe(1)
        ->and($rows[0]['days_since_application'])->toBe(13)
        ->and($rows[0]['last_control_date'])->toBe('15.11.2026')
        ->and($rows[1]['applied_on'])->toBeNull();

    expect($service->apartmentQuery('binda')->pluck('id')->all())->toBe([11])
        ->and($service->apartmentQuery('AIRES, 20')->pluck('id')->all())->toBe([10]);
});

it('returns an empty dashboard rather than showing every apartment or fake numbers', function (): void {
    DB::table('apartments')->insert(['id' => 10, 'name' => 'No coating', 'address' => null]);
    $service = app(AntigocciaOverview::class);

    expect($service->totals())->toBe([
        'treated_apartments' => 0, 'reports' => 0, 'with_contamination' => 0, 'last_30_days' => 0,
    ]);
    expect($service->apartmentQuery()->get())->toBeEmpty();
    expect($service->rowsForApartments(collect()))->toBe([]);
});

it('counts submitted questionnaires and distinguishes current-cycle coverage from earlier observations', function (): void {
    DB::table('apartments')->insert([
        ['id' => 10, 'name' => 'A', 'address' => null],
        ['id' => 11, 'name' => 'B', 'address' => null],
        ['id' => 12, 'name' => 'C', 'address' => null],
    ]);
    DB::table('antigoccia_applications')->insert([
        ['apartment_id' => 10, 'applied_on' => '2026-11-10'],
        ['apartment_id' => 10, 'applied_on' => '2026-11-20'],
        ['apartment_id' => 11, 'applied_on' => '2026-11-10'],
    ]);
    DB::table('antigoccia_reports')->insert([
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-18', 'user_id' => 1, 'cleaner_name' => 'A', 'created_at' => '2026-11-21 10:00:00'],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-18', 'user_id' => 1, 'cleaner_name' => 'A', 'created_at' => '2026-11-21 11:00:00'],
        ['apartment_id' => 11, 'cleaning_date' => '2026-11-22', 'user_id' => 2, 'cleaner_name' => 'B', 'created_at' => '2026-11-23 10:00:00'],
        ['apartment_id' => 12, 'cleaning_date' => '2026-11-22', 'user_id' => 2, 'cleaner_name' => 'B', 'created_at' => '2026-11-23 11:00:00'],
        ['apartment_id' => 10, 'cleaning_date' => '2026-11-12', 'user_id' => 1, 'cleaner_name' => 'A', 'created_at' => '2026-11-12 11:00:00'],
    ]);

    $activity = app(AntigocciaOverview::class)->activity(7);

    expect($activity['reports'])->toBe(4)
        ->and($activity['treated'])->toBe(2)
        ->and($activity['covered'])->toBe(1)
        ->and($activity['uncovered'])->toBe(1)
        ->and($activity['staff_count'])->toBe(2)
        ->and($activity['daily']['2026-11-17'])->toBe(0)
        ->and($activity['daily']['2026-11-21'])->toBe(2)
        ->and($activity['apartments'][0]['covered'])->toBeFalse()
        ->and($activity['apartments'][1]['mean_interval'])->toEqual(4)
        ->and($activity['apartments'][2]['applied_on'])->toBeNull();
});

<?php

use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequestDay;
use App\Services\Telegram\TelegramTomorrowSummaryBuilder;
use App\Services\Telegram\TelegramTomorrowSummaryFormatter;
use App\Services\Weather\MilanWeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->boolean('is_active');
        $table->timestamps();
    });
    foreach (['day_off_requests', 'vacation_requests'] as $name) {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->timestamps();
        });
    }
    foreach (['day_off_request_days', 'vacation_request_days'] as $name) {
        Schema::create($name, function (Blueprint $table) use ($name) {
            $table->id();
            $table->unsignedBigInteger($name === 'day_off_request_days' ? 'day_off_request_id' : 'vacation_request_id');
            $table->unsignedBigInteger('user_id');
            $table->date('date');
            $table->string('status');
            $table->timestamps();
        });
    }
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/Rome'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('vacation_request_days');
    Schema::dropIfExists('day_off_request_days');
    Schema::dropIfExists('vacation_requests');
    Schema::dropIfExists('day_off_requests');
    Schema::dropIfExists('users');
});

function tomorrowUser(string $name, bool $active = true, string $status = 'approved'): User
{
    return User::create(['name' => $name, 'is_active' => $active, 'status' => $status]);
}

function tomorrowDay(string $model, User $user, string $date, string $status): void
{
    $model::create([
        $model === DayOffRequestDay::class ? 'day_off_request_id' : 'vacation_request_id' => 1,
        'user_id' => $user->id,
        'date' => $date,
        'status' => $status,
    ]);
}

function fakeTomorrowWeather(int $rain = 0, int $probability = 0): void
{
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'hourly' => [
            'time' => ['2026-10-09T08:00', '2026-10-09T13:00'],
            'temperature_2m' => [15, 19],
            'rain' => [0, $rain],
            'precipitation_probability' => [0, $probability],
            'weather_code' => [2, $rain > 0 ? 65 : 2],
            'wind_speed_10m' => [5, 8],
        ],
    ])]);
}

it('defaults to tomorrow in Europe/Rome and requests weather for that date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 23:30:00', 'Europe/Rome'));
    fakeTomorrowWeather();

    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--json' => true]))->toBe(0);
    $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($summary['date'])->toBe('2026-10-09')
        ->and($summary['timezone'])->toBe('Europe/Rome')
        ->and($summary['weather']['summary'])->toBe('+15…+19°C, облачно');
    Http::assertSent(fn ($request) => $request['start_date'] === '2026-10-09' && $request['end_date'] === '2026-10-09');
});

it('uses an explicit date as the target and excludes other approved dates', function () {
    fakeTomorrowWeather();
    $user = tomorrowUser('Anna Rossi');
    tomorrowDay(DayOffRequestDay::class, $user, '2026-10-09', 'approved');

    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--date' => '2026-10-10', '--json' => true]))->toBe(0);
    $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($summary['date'])->toBe('2026-10-10')
        ->and($summary['absences'])->toBeEmpty();
    Http::assertSent(fn ($request) => $request['start_date'] === '2026-10-10');
});

it('includes only approved individual days for active staff and deduplicates overlapping absences', function () {
    $anna = tomorrowUser('Anna Rossi');
    $maria = tomorrowUser('Maria Bianchi');
    DB::table('day_off_requests')->insert(['id' => 1, 'user_id' => $anna->id, 'status' => 'partially_approved']);
    DB::table('vacation_requests')->insert(['id' => 1, 'user_id' => $maria->id, 'status' => 'partially_approved']);
    $inactive = tomorrowUser('Inactive Worker', false);
    $unapproved = tomorrowUser('Pending Worker', true, 'pending');
    tomorrowDay(DayOffRequestDay::class, $anna, '2026-10-09', 'approved');
    tomorrowDay(VacationRequestDay::class, $anna, '2026-10-09', 'approved');
    tomorrowDay(VacationRequestDay::class, $maria, '2026-10-09', 'approved');
    tomorrowDay(VacationRequestDay::class, $maria, '2026-10-10', 'rejected');
    tomorrowDay(DayOffRequestDay::class, $anna, '2026-10-10', 'pending');
    tomorrowDay(DayOffRequestDay::class, $inactive, '2026-10-09', 'approved');
    tomorrowDay(DayOffRequestDay::class, $unapproved, '2026-10-09', 'approved');
    tomorrowDay(DayOffRequestDay::class, tomorrowUser('Rejected Day'), '2026-10-09', 'rejected');
    tomorrowDay(VacationRequestDay::class, tomorrowUser('Pending Day'), '2026-10-09', 'pending');
    $this->app->instance(MilanWeatherService::class, Mockery::mock(MilanWeatherService::class, fn ($mock) => $mock->shouldReceive('forDate')->andReturn(null)));

    $summary = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $nextDay = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse('2026-10-10', 'Europe/Rome'));

    expect($summary['absences'])->toBe([
        ['user_id' => $anna->id, 'name' => 'Anna Rossi', 'reason' => 'отпуск'],
        ['user_id' => $maria->id, 'name' => 'Maria Bianchi', 'reason' => 'отпуск'],
    ])->and($nextDay['absences'])->toBeEmpty();
});

it('renders approved day off and vacation without claiming an inferred shift', function () {
    $anna = tomorrowUser('Anna Rossi');
    $maria = tomorrowUser('Maria Bianchi');
    tomorrowDay(DayOffRequestDay::class, $anna, '2026-10-09', 'approved');
    tomorrowDay(VacationRequestDay::class, $maria, '2026-10-09', 'approved');
    fakeTomorrowWeather();

    $summary = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $text = app(TelegramTomorrowSummaryFormatter::class)->format($summary);

    expect($text)->toContain('🌅 Завтра · 09.10', '🏖 Не работают', '• Anna Rossi — выходной', '• Maria Bianchi — отпуск', '🌤 Погода')
        ->not->toContain('👥 Смена', '18 сотрудников', 'Что учитывать', 'Осталось с прошлых дней');
});

it('uses the existing weather wording and adds concrete impact only for severe weather', function () {
    fakeTomorrowWeather(5, 80);
    $builder = app(TelegramTomorrowSummaryBuilder::class);
    $formatter = app(TelegramTomorrowSummaryFormatter::class);
    $date = CarbonImmutable::parse('2026-10-09', 'Europe/Rome');

    $severe = $formatter->format($builder->build($date));
    expect($severe)->toContain('после 13:00 сильный дождь', 'После обеда лучше заложить больше времени на дорогу между квартирами.')
        ->not->toContain('Возможны задержки из-за погоды', 'Будьте осторожны');
});

it('keeps mild weather concise without a generic warning', function () {
    fakeTomorrowWeather();
    $summary = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $mild = app(TelegramTomorrowSummaryFormatter::class)->format($summary);
    expect($mild)->toContain('+15…+19°C, облачно')
        ->not->toContain('заложить больше времени', 'Возможны задержки из-за погоды');
});

it('omits unsupported sections and renders a clean zero state without consulting historical OI', function () {
    $weather = Mockery::mock(MilanWeatherService::class);
    $weather->shouldReceive('forDate')->once()->andReturn(null);
    $this->app->instance(MilanWeatherService::class, $weather);

    $summary = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $text = app(TelegramTomorrowSummaryFormatter::class)->format($summary);

    expect($text)->toBe("🌅 Завтра · 09.10\n\n✅ На завтра подтверждённых особенностей нет.")
        ->not->toContain('Смена', 'Что учитывать', 'Незакрытых проблем');
});

it('does not invent a forecast for historical or out-of-range target dates', function () {
    Http::fake();

    foreach (['2026-10-07', '2026-11-01'] as $date) {
        $summary = app(TelegramTomorrowSummaryBuilder::class)->build(CarbonImmutable::parse($date, 'Europe/Rome'));
        expect($summary['weather'])->toBeNull();
    }

    Http::assertNothingSent();
});

it('rejects an invalid target date', function () {
    expect(Artisan::call('telegram:tomorrow-summary-preview', ['--date' => '2026-02-30']))->toBe(1);
});

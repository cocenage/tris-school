<?php

use App\Console\Commands\MobilityDigestCommand;
use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Operations\OperationalContextBuilder;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    Http::fake(['*' => Http::response(['hourly' => []])]);
    config(['services.telegram.digest_districts' => []]);
    Schema::dropIfExists('mobility_alerts');
    Schema::create('mobility_alerts', function (Blueprint $table): void {
        $table->id();
        $table->string('source')->nullable();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->string('risk')->default('medium');
        $table->string('district')->nullable();
        $table->date('starts_at')->nullable();
        $table->date('ends_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->string('external_hash')->unique();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('mobility_alerts');
});

it('deduplicates mobility events and never treats a regular status as a high alert', function () {
    config([
        'services.telegram.mobility_targets' => null,
        'services.telegram.analytics_bot_token' => null,
    ]);

    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'M2 REGOLARE',
        'type' => 'info',
        'risk' => 'high',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'regular',
    ]);
    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'M2 chiusura tra Gobba e Cologno Nord',
        'description' => 'Autobus sostitutivi da Gobba https://tracker.invalid/x',
        'type' => 'partial_closure',
        'risk' => 'medium',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'closure-a',
    ]);
    MobilityAlert::create([
        'source' => 'milanmetrost',
        'title' => 'M2 chiusura tra Gobba e Cologno Nord!',
        'description' => 'Autobus sostitutivi da Gobba',
        'type' => 'partial_closure',
        'risk' => 'medium',
        'district' => 'M2',
        'starts_at' => '2026-08-03',
        'external_hash' => 'closure-b',
    ]);

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $method->setAccessible(true);
    $message = $method->invoke(
        $command,
        Carbon::parse('2026-08-03'),
        MobilityAlert::query()->get()
    );

    expect($message)
        ->toContain('Передвижение')
        ->toContain('Gobba')
        ->toContain('автобусы')
        ->not->toContain('HIGH')
        ->not->toContain('REGOLARE');

    $this->artisan('mobility:digest', ['--date' => '2026-08-03', '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Передвижение');
});

it('splits metro line statuses and canonicalizes the Crescenzago cluster', function () {
    $normalizer = app(MobilityAlertSyncService::class);
    $events = $normalizer->splitTelegramStatusEvents(
        'M1 REGOLARE M2 PARZ. SOSPESA M3 CHIUSA M4 CHIUSA M5 CHIUSA'
    );

    expect(collect($events)->pluck('line')->all())
        ->toBe(['M2', 'M3', 'M4', 'M5']);
    expect($events[0]['risk'])->toBe('medium')
        ->and($events[1]['risk'])->toBe('high');

    $first = $normalizer->canonicalFingerprint(
        'telegram',
        'M2 bus BM2 Gobba - Cologno Nord',
        'Crescenzago closed',
        'partial_closure',
        '2026-08-03',
    );
    $second = $normalizer->canonicalFingerprint(
        'atm',
        'Crescenzago closed!',
        'M2 buses Gobba ↔ Cologno Nord',
        'closure',
        '2026-08-03',
    );

    expect($first)->toBe($second);
});

it('normalizes operational mobility items into separate non-raw line events', function () {
    $builder = app(OperationalContextBuilder::class);
    $method = new ReflectionMethod($builder, 'normalizeMobilityAlert');
    $method->setAccessible(true);

    $alert = new MobilityAlert([
        'source' => 'telegram',
        'title' => 'Linea M1 REGOLARE Linea M2 PARZ. SOSPESA Linea M3 CHIUSA Linea M4 CHIUSA Linea M5 CHIUSA',
        'description' => 'MilanMetroStatus.it è anche su ChatGPT',
        'type' => 'transport',
        'risk' => 'high',
        'starts_at' => '2026-08-03',
    ]);

    $items = collect($method->invoke($builder, $alert));

    expect($items->pluck('district')->all())->toBe(['M2', 'M3', 'M4', 'M5'])
        ->and($items->pluck('risk')->all())->toBe(['medium', 'high', 'high', 'high'])
        ->and($items->pluck('title')->implode(' '))->not->toContain('Linea');
});

it('suppresses raw M1-M5 and Crescenzago source rows when normalized events exist', function () {
    $normalizer = app(MobilityAlertSyncService::class);
    $rawMetro = MobilityAlert::make([
        'source' => 'telegram',
        'title' => 'Linea M1 CHIUSA Linea M2 PARZ. SOSPESA Linea M3 CHIUSA Linea M4 CHIUSA Linea M5 CHIUSA',
        'type' => 'transport',
        'risk' => 'high',
        'starts_at' => '2026-08-03',
    ]);
    $normalizedLines = collect(['M1 CHIUSA', 'M2 PARZ. SOSPESA', 'M3 CHIUSA', 'M4 CHIUSA', 'M5 CHIUSA'])
        ->map(fn (string $title): MobilityAlert => MobilityAlert::make([
            'source' => 'telegram',
            'title' => $title,
            'type' => str_contains($title, 'PARZ') ? 'partial_closure' : 'closure',
            'risk' => str_contains($title, 'PARZ') ? 'medium' : 'high',
            'starts_at' => '2026-08-03',
        ]));
    $rawCrescenzago = MobilityAlert::make([
        'source' => 'telegram',
        'title' => 'Crescenzago — INFO M2 buses Gobba Cologno Nord',
        'type' => 'info',
        'risk' => 'high',
        'starts_at' => '2026-08-03',
    ]);
    $normalizedM2 = MobilityAlert::make([
        'source' => 'telegram',
        'title' => 'M2 PARZ. SOSPESA',
        'type' => 'partial_closure',
        'risk' => 'medium',
        'starts_at' => '2026-08-03',
    ]);

    $filtered = $normalizer->filterRepresentedRawAlerts(
        collect([$rawMetro, ...$normalizedLines, $rawCrescenzago, $normalizedM2])
    );

    expect($filtered->contains($rawMetro))->toBeFalse()
        ->and($filtered->contains($rawCrescenzago))->toBeFalse()
        ->and($filtered->filter(fn (MobilityAlert $alert): bool => str_starts_with($alert->title, 'M'))->count())->toBe(6);

    $builder = app(OperationalContextBuilder::class);
    $normalize = new ReflectionMethod($builder, 'normalizeMobilityAlert');
    $normalize->setAccessible(true);
    $operational = $filtered
        ->flatMap(fn (MobilityAlert $alert) => $normalize->invoke($builder, $alert))
        ->unique(fn (array $item): string => $item['canonical_key']);

    expect($operational->count())->toBe(5)
        ->and($operational->pluck('title')->implode(' '))->not->toContain('Crescenzago')
        ->and($operational->pluck('title')->implode(' '))->not->toContain('Linea');

    $digest = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($digest, 'deduplicateAlerts');
    $method->setAccessible(true);
    $rendered = collect($method->invoke($digest, collect([$rawCrescenzago, $normalizedM2])));

    expect($rendered)->toHaveCount(1)
        ->and($rendered->first()['district'])->toBe('M2');
});

it('builds two regional dry-runs with distinct weather coordinates and evidence-based mobility scope', function () {
    config(['services.telegram.digest_districts' => [
        'navigli' => [
            'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
            'latitude' => 45.45, 'longitude' => 9.17,
        ],
        'lodi' => [
            'label' => 'Lodi', 'chat_id' => '-1002', 'duty_thread_id' => '22',
            'latitude' => 45.44, 'longitude' => 9.21,
        ],
    ]]);
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'hourly' => [
            'time' => ['2026-08-03T08:00'],
            'temperature_2m' => [22],
            'precipitation_probability' => [0],
            'rain' => [0],
            'weather_code' => [1],
            'wind_speed_10m' => [5],
        ],
    ])]);
    foreach ([
        ['district' => 'Navigli', 'title' => 'Chiusura Navigli', 'description' => 'Navigli only'],
        ['district' => 'Lodi', 'title' => 'Chiusura Lodi', 'description' => 'Lodi only'],
        ['district' => 'M2', 'title' => 'M2 chiusura', 'description' => 'M2 shared'],
    ] as $index => $item) {
        MobilityAlert::create($item + [
            'source' => 'test', 'type' => 'closure', 'risk' => 'high',
            'starts_at' => '2026-08-03', 'external_hash' => 'regional-'.$index,
        ]);
    }
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('mobility:digest', ['--date' => '2026-08-03', '--dry-run' => true]))->toBe(0);
    $output = mb_strtolower(Artisan::output());

    expect($output)->toContain('navigli')->toContain('lodi')
        ->and(substr_count($output, '⚠️ <b>navigli</b>'))->toBe(1)
        ->and(substr_count($output, '⚠️ <b>lodi</b>'))->toBe(1)
        ->and(substr_count($output, '⚠️ <b>m2</b>'))->toBe(2);
    Http::assertSentCount(2);
    $coordinates = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]['latitude'].'|'.$pair[0]['longitude']
    )->sort()->values()->all();
    expect($coordinates)->toBe(['45.44|9.21', '45.45|9.17']);
});

it('keeps a regional morning digest useful when weather is unavailable', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response([], 503)]);
    $alert = MobilityAlert::make([
        'source' => 'test', 'title' => 'Chiusura viabilità', 'description' => 'City-wide restriction',
        'type' => 'closure', 'risk' => 'high', 'starts_at' => '2026-08-03',
    ]);
    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $method->setAccessible(true);
    $message = $method->invoke($command, Carbon::parse('2026-08-03'), collect([$alert]), [
        'key' => 'navigli', 'label' => 'Navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)
        ->toContain('Navigli')
        ->toContain('погода временно недоступна')
        ->toContain('Передвижение')
        ->toContain('линия закрыта')
        ->toMatch('/Хорошей смены|Удачного дня|Отличной смены|Легкого рабочего дня|Пусть день пройдет спокойно/');
});

it('uses the existing sender and matching duty topics for regional morning delivery', function () {
    config(['services.telegram.digest_districts' => [
        'navigli' => [
            'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
            'latitude' => 45.45, 'longitude' => 9.17,
        ],
        'lodi' => [
            'label' => 'Lodi', 'chat_id' => '-1002', 'duty_thread_id' => '22',
            'latitude' => 45.44, 'longitude' => 9.21,
        ],
    ]]);
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => []])]);
    $this->mock(TelegramBotService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendMessage')->once()->with('-1001', Mockery::type('string'), '11')->andReturn(101);
        $mock->shouldReceive('sendMessage')->once()->with('-1002', Mockery::type('string'), '22')->andReturn(102);
    });

    $this->artisan('mobility:digest', ['--date' => '2026-08-03'])
        ->expectsOutputToContain('District morning digests sent')
        ->assertExitCode(0);
});

<?php

use App\Console\Commands\MobilityDigestCommand;
use App\Models\MobilityAlert;
use App\Models\TelegramOutboundOperation;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Mobility\MobilityStrikesSummaryBuilder;
use App\Services\Mobility\MobilityStrikesSummaryFormatter;
use App\Services\Operations\OperationalContextBuilder;
use App\Services\Telegram\TelegramBotService;
use App\Services\Weather\MilanWeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['services.weatherapi.key' => 'feature-test-key']);
    Carbon::setTestNow(Carbon::parse('2026-10-07 06:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 06:00', 'Europe/Rome'));
    Http::preventStrayRequests();
    Schema::dropIfExists('telegram_outbound_operation_events');
    Schema::dropIfExists('telegram_outbound_operations');
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    (require database_path('migrations/2026_11_01_000000_create_telegram_outbound_operation_events_table.php'))->up();
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
        $table->json('strike_metadata')->nullable();
        $table->string('external_hash')->unique();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('mobility_alerts');
    Schema::dropIfExists('telegram_outbound_operation_events');
    Schema::dropIfExists('telegram_outbound_operations');
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('journals empty morning mobility and weather sources before the inline delivery', function () {
    config([
        'services.telegram.digest_districts' => [
            'lodi' => ['label' => 'Lodi', 'chat_id' => 'test-chat', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        ],
        'services.telegram.analytics_bot_token' => 'analytics-test-token',
    ]);
    Http::fake([
        'api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 71]], 200),
    ]);

    expect(Artisan::call('mobility:digest', ['--date' => '2026-10-07']))->toBe(0);

    $operation = TelegramOutboundOperation::query()->where('operation_key', 'morning_digest:2026-10-07:lodi')->sole();
    expect($operation->events->where('stage', 'morning_alert_source')->first()?->outcome)->toBe('no_input')
        ->and($operation->events->where('stage', 'morning_filter')->first()?->outcome)->toBe('no_significant_events')
        ->and($operation->events->where('stage', 'morning_filter')->first()?->metrics)
        ->toBe(['input_count' => 0, 'filtered_count' => 0, 'included_count' => 0])
        ->and($operation->events->where('stage', 'morning_weather_source')->first()?->outcome)->toBe('no_input')
        ->and($operation->events->where('stage', 'morning_format')->first()?->metrics)->toBe(['message_present' => true])
        ->and($operation->events->pluck('stage')->last())->toBe('telegram_response');
});

it('journals source alerts removed by the existing morning relevance filter', function () {
    config([
        'services.telegram.digest_districts' => [
            'lodi' => ['label' => 'Lodi', 'chat_id' => 'test-chat', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        ],
        'services.telegram.analytics_bot_token' => 'analytics-test-token',
    ]);
    MobilityAlert::create([
        'source' => 'test', 'title' => 'private irrelevant event', 'description' => 'staff comment must not be logged',
        'type' => 'info', 'risk' => 'high', 'starts_at' => '2026-10-07', 'external_hash' => 'filtered-event',
    ]);
    Http::fake([
        'api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 72]], 200),
    ]);

    expect(Artisan::call('mobility:digest', ['--date' => '2026-10-07']))->toBe(0);

    $operation = TelegramOutboundOperation::query()->where('operation_key', 'morning_digest:2026-10-07:lodi')->sole();
    $source = $operation->events->where('stage', 'morning_alert_source')->first();
    $filter = $operation->events->where('stage', 'morning_filter')->first();
    expect($source?->outcome)->toBe('loaded')
        ->and($source?->metrics)->toBe(['input_count' => 1])
        ->and($filter?->outcome)->toBe('no_significant_events')
        ->and($filter?->metrics)->toBe(['input_count' => 1, 'filtered_count' => 1, 'included_count' => 0])
        ->and(json_encode($operation->events->toArray()))->not->toContain('private irrelevant event', 'staff comment must not be logged');
});

it('records a morning weather source error while preserving the existing fallback message path', function () {
    config([
        'services.telegram.digest_districts' => [
            'lodi' => ['label' => 'Lodi', 'chat_id' => 'test-chat', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        ],
        'services.telegram.analytics_bot_token' => 'analytics-test-token',
    ]);
    Http::fake([
        'api.weatherapi.com/*' => Http::response(['error' => ['code' => 1006]], 503),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 73]], 200),
    ]);

    expect(Artisan::call('mobility:digest', ['--date' => '2026-10-07']))->toBe(0);

    $operation = TelegramOutboundOperation::query()->where('operation_key', 'morning_digest:2026-10-07:lodi')->sole();
    expect($operation->events->where('stage', 'morning_weather_source')->first()?->outcome)->toBe('source_error')
        ->and($operation->events->where('stage', 'morning_format')->first()?->outcome)->toBe('built')
        ->and(json_encode($operation->events->toArray()))->not->toContain('1006', 'error', 'WeatherAPI');
});

it('keeps mobility filtering separate from the weather message and omits strike summaries', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    $strikeBuilder = Mockery::mock(MobilityStrikesSummaryBuilder::class);
    $strikeBuilder->shouldReceive('build')->zeroOrMoreTimes()->andReturn(['strikes' => [[
        'source' => 'atm', 'status' => 'scheduled', 'operator' => 'ATM Milano',
    ]]]);
    $strikeFormatter = Mockery::mock(MobilityStrikesSummaryFormatter::class);
    $strikeFormatter->shouldReceive('formatMorningSection')->zeroOrMoreTimes()->andReturn('SYNTHETIC_STRIKE_SECTION');
    app()->instance(MobilityStrikesSummaryBuilder::class, $strikeBuilder);
    app()->instance(MobilityStrikesSummaryFormatter::class, $strikeFormatter);

    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'Sciopero ATM confermato il 30 ottobre',
        'description' => 'Sciopero del trasporto pubblico.',
        'type' => 'strike',
        'risk' => 'high',
        'district' => 'Milano',
        'starts_at' => '2026-10-30',
        'ends_at' => '2026-10-30',
        'strike_metadata' => ['status' => 'scheduled'],
        'external_hash' => 'strike-morning-digest',
    ]);
    MobilityAlert::create([
        'source' => 'atm',
        'title' => 'Blocco totale della circolazione in zona Navigli',
        'description' => 'Deviazione attiva per lavori.',
        'type' => 'critical_closure',
        'risk' => 'critical',
        'district' => 'Navigli',
        'starts_at' => '2026-10-30',
        'external_hash' => 'non-strike-morning-alert',
    ]);

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke(
        $command,
        Carbon::parse('2026-10-30', 'Europe/Rome'),
        MobilityAlert::query()->get(),
    );

    expect($message)
        ->toContain('Погода на сегодня', 'Отличной смены 🚀')
        ->not->toContain('Сегодня важно', 'Blocco totale della circolazione in zona Navigli', 'Navigli')
        ->not->toContain('Sciopero', 'Забастовка транспорта', 'trasporto pubblico.', 'SYNTHETIC_STRIKE_SECTION');
    $strikeBuilder->shouldNotHaveReceived('build');
    $strikeFormatter->shouldNotHaveReceived('formatMorningSection');
});

it('keeps routine metro closures out of the human morning digest', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
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
        ->not->toContain('Сегодня важно')
        ->not->toContain('Передвижение')
        ->not->toContain('Gobba')
        ->not->toContain('HIGH')
        ->not->toContain('REGOLARE');

    $this->artisan('mobility:digest', ['--date' => '2026-08-03', '--dry-run' => true])
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Сегодня важно');
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

it('builds two regional weather dry-runs and keeps mobility alerts out of their message content', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 06:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 06:00', 'Europe/Rome'));
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
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [[
        'date' => '2026-08-03',
        'hour' => [[
            'time' => '2026-08-03 08:00',
            'time_epoch' => CarbonImmutable::parse('2026-08-03 08:00', 'Europe/Rome')->getTimestamp(),
            'temp_c' => 22, 'feelslike_c' => 22, 'humidity' => 50,
            'wind_kph' => 5, 'wind_degree' => 90, 'wind_dir' => 'E',
            'chance_of_rain' => 0, 'precip_mm' => 0,
            'condition' => ['text' => 'Преимущественно ясно', 'code' => 1003],
        ]],
    ]]]])]);
    foreach ([
        ['district' => 'Navigli', 'title' => 'Interruzione totale viabilità Navigli', 'description' => 'Interruzione totale della circolazione dalle 18:00'],
        ['district' => 'Lodi', 'title' => 'Interruzione totale viabilità Lodi', 'description' => 'Interruzione totale della circolazione dalle 19:00'],
        ['district' => 'M2', 'title' => 'M2 chiusura', 'description' => 'M2 shared'],
    ] as $index => $item) {
        MobilityAlert::create($item + [
            'source' => 'test', 'type' => 'closure', 'risk' => 'critical',
            'starts_at' => '2026-08-03', 'external_hash' => 'regional-'.$index,
        ]);
    }
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('mobility:digest', ['--date' => '2026-08-03', '--dry-run' => true]))->toBe(0);
    $output = mb_strtolower(Artisan::output());

    expect($output)->toContain('navigli')->toContain('lodi')
        ->and(substr_count($output, '🌤 <b>погода на сегодня</b>'))->toBe(2)
        ->and(substr_count($output, 'отличной смены 🚀'))->toBe(2)
        ->and($output)->not->toContain(
            'interruzione totale della circolazione', 'сегодня важно', 'm2 chiusura'
        );
    Http::assertSentCount(2);
    $coordinates = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]['q'])->sort()->values()->all();
    expect($coordinates)->toBe(['45.44,9.21', '45.45,9.17']);
});

it('keeps a regional morning digest concise when weather is unavailable', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 06:00', 'Europe/Rome'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 06:00', 'Europe/Rome'));
    Http::fake(['api.weatherapi.com/*' => Http::response([], 503)]);
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
        ->toBe(
            "🌤 <b>Погода на сегодня</b>\n\n"
            ."ℹ️ <b>08:00 · Прогноз временно недоступен</b>\n\n"
            ."ℹ️ <b>13:00 · Прогноз временно недоступен</b>\n\n"
            ."ℹ️ <b>15:00 · Прогноз временно недоступен</b>\n\n"
            .'Отличной смены 🚀'
        )
        ->not->toContain('Navigli', 'Передвижение', 'Сегодня важно', 'WeatherAPI.com');
});

it('renders one concise weather recommendation without mobility details', function () {
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('morningForecast')->once()->andReturn([
            'points' => [
                '08:00' => [
                    'emoji' => '☀️', 'condition' => 'Ясно', 'temperature_c' => 16,
                    'apparent_temperature_c' => 15, 'relative_humidity_pct' => 55,
                    'wind_speed_kmh' => 5, 'wind_direction' => 'З', 'precipitation_probability_pct' => 0,
                ],
                '13:00' => [
                    'emoji' => '🌧', 'condition' => 'Дождь', 'temperature_c' => 18,
                    'apparent_temperature_c' => 16, 'relative_humidity_pct' => 78,
                    'wind_speed_kmh' => 8, 'wind_direction' => 'ЮЗ', 'precipitation_probability_pct' => 90,
                    'weather_code' => 1195, 'rain_mm' => 5,
                ],
                '15:00' => null,
            ],
            'advice' => '⚠️ Сильный дождь около 13:00 — будьте осторожны на улице.',
        ]));
    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke($command, Carbon::parse('2026-10-07'), collect(), [
        'key' => 'navigli', 'label' => 'Navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)->toContain('08:00 · Ясно — +16°C', '13:00 · Дождь — +18°C', 'Ощущается +15°', 'Ощущается +16°')
        ->toContain('⚠️ Сильный дождь около 13:00 — будьте осторожны на улице.')
        ->toContain('Отличной смены 🚀')
        ->not->toContain('78%', '90%', 'Влажность', 'км/ч', 'между квартирами', 'Сегодня важно');
});

it('renders all three forecast hours for all five configured districts for the requested dry-run date', function () {
    $districts = collect(['navigli', 'lodi', 'certosa', 'lambrate', 'como'])
        ->mapWithKeys(fn (string $key, int $index): array => [$key => [
            'label' => ucfirst($key), 'chat_id' => (string) (-1001 - $index),
            'duty_thread_id' => (string) (11 + $index),
            'latitude' => 45.40 + ($index * 0.01), 'longitude' => 9.10 + ($index * 0.01),
        ]])->all();
    config(['services.telegram.digest_districts' => $districts]);

    $hours = collect([
        ['08:00', 12, 10, 82, 8, 315, 20, 0, 'Облачно', 1006],
        ['13:00', 18, 17, 60, 12, 270, 10, 0, 'Ясно', 1000],
        ['15:00', 16, 14, 75, 19, 225, 65, 1.2, 'Дождь', 1183],
    ])->map(fn (array $values): array => [
        'time' => '2026-10-07 '.$values[0],
        'time_epoch' => CarbonImmutable::parse('2026-10-07 '.$values[0], 'Europe/Rome')->getTimestamp(),
        'temp_c' => $values[1], 'feelslike_c' => $values[2], 'humidity' => $values[3],
        'wind_kph' => $values[4], 'wind_degree' => $values[5], 'wind_dir' => 'NW',
        'chance_of_rain' => $values[6], 'precip_mm' => $values[7],
        'condition' => ['text' => $values[8], 'code' => $values[9]],
    ])->all();
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [[
        'date' => '2026-10-07', 'hour' => $hours,
    ]]]])]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendAnalyticsMessage'));

    expect(Artisan::call('mobility:digest', ['--date' => '2026-10-07', '--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    foreach (['Navigli', 'Lodi', 'Certosa', 'Lambrate', 'Como'] as $district) {
        expect($output)->toContain('DRY RUN MORNING DIGEST · '.$district);
    }
    expect(substr_count($output, '08:00 ·'))->toBe(5)
        ->and(substr_count($output, '13:00 ·'))->toBe(5)
        ->and(substr_count($output, '15:00 ·'))->toBe(5)
        ->and(substr_count($output, 'WeatherAPI.com'))->toBe(5)
        ->and(substr_count($output, 'официальными метеослужбами'))->toBe(5)
        ->and(substr_count($output, 'Отличной смены 🚀'))->toBe(5)
        ->and(substr_count($output, '1,2 мм'))->toBe(0);

    preg_match_all('/===== DRY RUN MORNING DIGEST · .+? =====\r?\n(.*?)\r?\n===================================/s', $output, $messages);
    expect($messages[1])->toHaveCount(5);

    foreach ($messages[1] as $message) {
        preg_match_all('/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $message, $tags);
        $visibleText = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect(mb_strlen($visibleText, 'UTF-8'))->toBeLessThanOrEqual(4096)
            ->and(array_values(array_unique(array_map('strtolower', $tags[1]))))->toBe(['b'])
            ->and(substr_count($message, '<b>'))->toBe(substr_count($message, '</b>'))
            ->and($message)->not->toContain('Navigli', 'Сегодня важно', 'weatherapi.com', 'href=');
    }
    $requests = collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    expect($requests)->toHaveCount(5)
        ->and($requests->every(fn (Request $request): bool => (int) $request['days'] === 3
            && $request['aqi'] === 'no' && $request['alerts'] === 'no'
            && $request['lang'] === 'ru'))->toBeTrue()
        ->and($requests->pluck('q')->all())->toBe(['45.4,9.1', '45.41,9.11', '45.42,9.12', '45.43,9.13', '45.44,9.14']);
});

it('uses Europe/Rome for the default forecast date and hourly points', function () {
    config([
        'app.timezone' => 'UTC',
        'services.telegram.digest_districts' => [
            'navigli' => [
                'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
                'latitude' => 45.45, 'longitude' => 9.17,
            ],
        ],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-06 22:30:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('morningForecast')->once()->withArgs(
            fn (CarbonImmutable $date, float $latitude, float $longitude, string $timezone): bool => $date->toDateString() === '2026-10-07' && $timezone === 'Europe/Rome'
        )->andReturn([
            'points' => ['08:00' => null, '13:00' => null, '15:00' => null],
            'advice' => null,
            'unavailable_reason' => 'provider_error',
        ]));
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendAnalyticsMessage'));

    expect(Artisan::call('mobility:digest', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Погода на сегодня', 'Прогноз временно недоступен');
});

it('omits strike announcements and routine M1-M5 statuses from every district morning digest', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    config(['services.telegram.digest_districts' => [
        'navigli' => [
            'label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11',
            'latitude' => 45.45, 'longitude' => 9.17,
        ],
        'como' => [
            'label' => 'Como', 'chat_id' => '-1002', 'duty_thread_id' => '22',
            'latitude' => 45.80, 'longitude' => 9.08,
        ],
    ]]);
    MobilityAlert::create([
        'source' => 'test', 'title' => 'Sciopero del trasporto pubblico',
        'description' => 'Sciopero dalle 18:00 a fine servizio',
        'type' => 'strike', 'risk' => 'high', 'district' => null,
        'starts_at' => '2026-09-18', 'external_hash' => 'city-strike',
    ]);
    foreach (range(1, 5) as $line) {
        MobilityAlert::create([
            'source' => 'test', 'title' => "M{$line} chiusura",
            'type' => 'closure', 'risk' => 'high', 'district' => "M{$line}",
            'starts_at' => '2026-09-18', 'external_hash' => "metro-{$line}",
        ]);
    }
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(Artisan::call('mobility:digest', ['--date' => '2026-09-18', '--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    expect(substr_count($output, 'Забастовка общественного транспорта с 18:00 до конца движения.'))->toBe(0)
        ->and(substr_count($output, 'Сегодня важно'))->toBe(0)
        ->and($output)->not->toContain('M1 chiusura')->not->toContain('M5 chiusura');
});

it('keeps qualifying mobility alerts out of the weather-only message', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $method->setAccessible(true);
    $alerts = collect([
        MobilityAlert::make([
            'source' => 'test', 'title' => 'Blocco totale della circolazione',
            'description' => 'Servizio sospeso su tutta la rete',
            'type' => 'disruption', 'risk' => 'critical',
            'starts_at' => '2026-09-18',
        ]),
        MobilityAlert::make([
            'source' => 'test', 'title' => 'M3 chiusura',
            'type' => 'closure', 'risk' => 'critical', 'district' => 'M3',
            'starts_at' => '2026-09-18',
        ]),
    ]);

    $message = $method->invoke($command, Carbon::parse('2026-09-18'), $alerts, [
        'key' => 'navigli', 'label' => 'Navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)
        ->toContain('Погода на сегодня', 'Отличной смены 🚀')
        ->not->toContain('Сегодня важно', 'Servizio sospeso su tutta la rete', 'M3 chiusura');
});

it('uses the analytics sender and matching duty topics for regional morning delivery', function () {
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
    $weather = [
        'points' => [
            '08:00' => ['emoji' => '🌤', 'condition' => 'Ясно', 'temperature_c' => 12, 'apparent_temperature_c' => 10],
            '13:00' => ['emoji' => '🌤', 'condition' => 'Ясно', 'temperature_c' => 18, 'apparent_temperature_c' => 17],
            '15:00' => ['emoji' => '🌤', 'condition' => 'Ясно', 'temperature_c' => 16, 'apparent_temperature_c' => 15],
        ],
        'advice' => null,
        'unavailable_reason' => null,
    ];
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('supportsForecastDate')->once()->andReturnTrue()
        ->shouldReceive('morningForecast')->twice()->andReturn($weather));
    $sent = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$sent): void {
        $mock->shouldReceive('sendAnalyticsMessage')->once()->with('-1001', Mockery::type('string'), '11')
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent): int {
                $sent[] = $text;

                return 101;
            });
        $mock->shouldReceive('sendAnalyticsMessage')->once()->with('-1002', Mockery::type('string'), '22')
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent): int {
                $sent[] = $text;

                return 102;
            });
    });

    $this->artisan('mobility:digest', ['--date' => '2026-10-07'])
        ->expectsOutputToContain('District morning digests sent')
        ->assertExitCode(0);

    expect($sent)->toHaveCount(2);
    foreach ($sent as $message) {
        expect($message)
            ->toContain('🌤 <b>Погода на сегодня</b>', '08:00', '13:00', '15:00', 'Источник: WeatherAPI.com.', 'Отличной смены 🚀')
            ->not->toContain('Navigli', 'Lodi', 'Сегодня важно', 'href=', 'захватите зонт', 'возможен дождик');
    }
});

it('attempts the morning summary for every configured district', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    config(['services.telegram.digest_districts' => collect(['navigli', 'lodi', 'certosa', 'lambrate', 'como'])
        ->mapWithKeys(fn (string $key, int $index): array => [$key => [
            'label' => ucfirst($key), 'chat_id' => (string) (-1001 - $index),
            'duty_thread_id' => (string) (11 + $index),
            'latitude' => 45.45, 'longitude' => 9.17,
        ]])->all()]);
    $sent = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$sent): void {
        $mock->shouldReceive('sendAnalyticsMessage')->times(5)
            ->andReturnUsing(function (string $chatId, string $text, string $threadId) use (&$sent): int {
                $sent[] = [$chatId, $threadId, $text];

                return count($sent);
            });
    });

    $this->artisan('mobility:digest', ['--date' => '2026-10-07'])->assertExitCode(0);

    expect(collect($sent)->pluck('0')->all())->toBe(['-1001', '-1002', '-1003', '-1004', '-1005'])
        ->and(collect($sent)->pluck('1')->all())->toBe(['11', '12', '13', '14', '15']);
});

it('continues to later districts when an earlier morning send fails', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    config(['services.telegram.digest_districts' => [
        'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        'lodi' => ['label' => 'Lodi', 'chat_id' => '-1002', 'duty_thread_id' => '22', 'latitude' => 45.45, 'longitude' => 9.17],
        'como' => ['label' => 'Como', 'chat_id' => '-1003', 'duty_thread_id' => '33', 'latitude' => 45.45, 'longitude' => 9.17],
    ]]);
    $attempted = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$attempted): void {
        $mock->shouldReceive('sendAnalyticsMessage')->times(3)
            ->andReturnUsing(function (string $chatId) use (&$attempted): ?int {
                $attempted[] = $chatId;
                if ($chatId === '-1001') {
                    throw new RuntimeException('Transport failed');
                }

                return $chatId === '-1002' ? null : 103;
            });
    });

    $this->artisan('mobility:digest', ['--date' => '2026-10-07'])->assertExitCode(1);

    expect($attempted)->toBe(['-1001', '-1002', '-1003']);
});

it('reports incomplete district routes instead of silently sending only the first valid route', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response(['forecast' => ['forecastday' => [['date' => '2026-10-07', 'hour' => []]]]])]);
    config(['services.telegram.digest_districts' => [
        'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        'lodi' => ['label' => 'Lodi', 'chat_id' => '-1002', 'latitude' => 45.45, 'longitude' => 9.17],
    ]]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('sendAnalyticsMessage')->once()->with('-1001', Mockery::type('string'), '11')->andReturn(101));

    $this->artisan('mobility:digest', ['--date' => '2026-10-07'])
        ->expectsOutputToContain('District lodi skipped: missing_duty_thread_id')
        ->assertExitCode(1);
});

it('does not send a requested date outside the WeatherAPI Free forecast window', function () {
    config(['services.telegram.digest_districts' => [
        'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
    ]]);
    Http::fake();
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendAnalyticsMessage'));

    $this->artisan('mobility:digest', ['--date' => '2026-11-03'])
        ->expectsOutputToContain('no digest was sent')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('renders the approved weather layout with attribution, safe HTML and Telegram length', function () {
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('morningForecast')->once()->andReturn([
            'points' => [
                '08:00' => [
                    'emoji' => '🌧', 'condition' => 'Возможен дождь', 'temperature_c' => 11,
                    'apparent_temperature_c' => 10, 'relative_humidity_pct' => 78,
                    'wind_speed_kmh' => 19, 'wind_direction' => 'В',
                    'precipitation_probability_pct' => 46, 'rain_mm' => 0.3,
                ],
                '13:00' => [
                    'emoji' => '☁️', 'condition' => 'Пасмурно', 'temperature_c' => 13,
                    'apparent_temperature_c' => 11, 'relative_humidity_pct' => 78,
                    'wind_speed_kmh' => 19, 'wind_direction' => 'В',
                    'precipitation_probability_pct' => 0, 'rain_mm' => 0,
                ],
                '15:00' => [
                    'emoji' => '☁️', 'condition' => 'Пасмурно', 'temperature_c' => 13,
                    'apparent_temperature_c' => 11, 'relative_humidity_pct' => 78,
                    'wind_speed_kmh' => 19, 'wind_direction' => 'В',
                    'precipitation_probability_pct' => 0, 'rain_mm' => 0,
                ],
            ],
            'advice' => null,
            'unavailable_reason' => null,
        ]));

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke($command, Carbon::parse('2026-10-07', 'Europe/Rome'), collect(), [
        'key' => 'navigli', 'label' => '<Navigli & Co>', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    $expectedMessage = "🌤 <b>Погода на сегодня</b>\n\n"
        ."🌧 <b>08:00 · Возможен дождь — +11°C</b>\nОщущается +10°\n\n"
        ."☁️ <b>13:00 · Пасмурно — +13°C</b>\nОщущается +11°\n\n"
        ."☁️ <b>15:00 · Пасмурно — +13°C</b>\nОщущается +11°\n\n"
        ."🌧 Утром возможен дождик — захватите зонт.\n\n"
        ."Источник: WeatherAPI.com.\n"
        .'ℹ️ Только для общего сведения: прогноз вероятностный и может быть неточным для конкретного места и времени. Не используйте его как единственную основу решений о личной безопасности, авиации, морской навигации, экстренном планировании и иных вопросах, критичных для безопасности; сверяйтесь с официальными метеослужбами и предупреждениями компетентных органов.'
        ."\n\nОтличной смены 🚀";

    preg_match_all('/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $message, $tags);
    $visibleText = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($message)->toBe($expectedMessage)
        ->toContain('08:00', '13:00', '15:00', 'Источник: WeatherAPI.com.', 'личной безопасности', 'авиации', 'морской навигации', 'экстренном планировании', 'официальными метеослужбами')
        ->not->toContain('Navigli', 'Влажность', 'км/ч', '46%', '0,3 мм', 'Сегодня важно', 'Доброе утро')
        ->not->toMatch('/<a\b|href=/i')
        ->and(substr_count($message, 'возможен дождик'))->toBe(1)
        ->and(substr_count($message, 'Отличной смены 🚀'))->toBe(1)
        ->and(array_values(array_unique(array_map('strtolower', $tags[1]))))->toBe(['b'])
        ->and(substr_count($message, '<b>'))->toBe(substr_count($message, '</b>'))
        ->and(mb_strlen($visibleText, 'UTF-8'))->toBeLessThanOrEqual(4096);
});

it('omits unavailable forecast measures instead of formatting them as zeroes', function () {
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('morningForecast')->once()->andReturn([
            'points' => [
                '08:00' => null,
                '13:00' => [
                    'emoji' => '☀️', 'condition' => 'Ясно', 'temperature_c' => 17,
                    'apparent_temperature_c' => null, 'relative_humidity_pct' => null,
                    'wind_speed_kmh' => null, 'wind_direction' => null,
                    'precipitation_probability_pct' => null, 'rain_mm' => null,
                ],
                '15:00' => null,
            ],
            'advice' => null,
            'unavailable_reason' => null,
        ]));

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke($command, Carbon::parse('2026-10-07'), collect(), [
        'key' => 'navigli', 'label' => 'Navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)
        ->toContain('08:00', '13:00', '15:00', '13:00 · Ясно — +17°C')
        ->toContain('08:00 · Прогноз временно недоступен', 'Ощущаемая температура недоступна', '15:00 · Прогноз временно недоступен')
        ->not->toContain('0 км/ч', '0%', '0,0 мм', 'Ощущается +0°');
});

it('does not format invalid temperature values as zeroes', function () {
    $this->mock(MilanWeatherService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('morningForecast')->once()->andReturn([
            'points' => [
                '08:00' => [
                    'emoji' => '☀️', 'condition' => 'Ясно', 'temperature_c' => 'unavailable',
                    'apparent_temperature_c' => 'unknown',
                ],
                '13:00' => null,
                '15:00' => null,
            ],
            'advice' => null,
            'unavailable_reason' => null,
        ]));

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke($command, Carbon::parse('2026-10-07', 'Europe/Rome'), collect(), [
        'key' => 'navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)
        ->toContain('08:00 · Ясно — температура недоступна', 'Ощущаемая температура недоступна')
        ->not->toContain('+0°C', '+0°');
});

it('names all forecast times when WeatherAPI is unavailable', function () {
    Http::fake(['api.weatherapi.com/*' => Http::response([], 503)]);

    $command = app(MobilityDigestCommand::class);
    $method = new ReflectionMethod($command, 'buildMessage');
    $message = $method->invoke($command, Carbon::parse('2026-10-07'), collect(), [
        'key' => 'navigli', 'label' => 'Navigli', 'latitude' => 45.45, 'longitude' => 9.17,
    ]);

    expect($message)
        ->toBe(
            "🌤 <b>Погода на сегодня</b>\n\n"
            ."ℹ️ <b>08:00 · Прогноз временно недоступен</b>\n\n"
            ."ℹ️ <b>13:00 · Прогноз временно недоступен</b>\n\n"
            ."ℹ️ <b>15:00 · Прогноз временно недоступен</b>\n\n"
            .'Отличной смены 🚀'
        )
        ->not->toContain('WeatherAPI.com', 'href=', 'Сегодня важно', 'Navigli');
});

<?php

use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Mobility\MobilityStrikeSourceStatus;
use App\Services\Mobility\MobilityStrikesSummaryBuilder;
use App\Services\Mobility\MobilityStrikesSummaryFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function morningStrike(array $attributes = [], array $metadata = []): MobilityAlert
{
    $identity = (string) ($attributes['external_hash'] ?? uniqid('morning-strike-', true));

    return MobilityAlert::create(array_replace([
        'source' => 'mit',
        'title' => 'Sciopero GRUPPO ATM DI MILANO',
        'description' => '08:45–15:00',
        'url' => 'https://scioperi.mit.gov.it/mit2/public/scioperi',
        'type' => 'strike',
        'risk' => 'high',
        'district' => 'Lombardia',
        'starts_at' => '2026-10-24',
        'ends_at' => '2026-10-24',
        'external_hash' => $identity,
        'strike_metadata' => array_replace([
            'identity' => $identity,
            'status' => 'scheduled',
            'sector' => 'Trasporto pubblico locale',
            'operator' => 'GRUPPO ATM DI MILANO',
            'duration' => '08:45–15:00',
            'scope' => 'Regionale',
            'notes' => '',
            'region' => 'Lombardia',
            'province' => 'Milano',
        ], $metadata),
    ], $attributes));
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    Schema::create('mobility_alerts', function (Blueprint $table): void {
        $table->id();
        $table->string('source');
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('url')->nullable();
        $table->string('type')->nullable();
        $table->string('risk');
        $table->string('district')->nullable();
        $table->date('starts_at')->nullable();
        $table->date('ends_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->string('external_hash')->unique();
        $table->json('strike_metadata')->nullable();
        $table->timestamps();
    });
    Schema::create('mobility_alert_messages', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('mobility_alert_id');
        $table->string('message_type');
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('queued_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('mobility_alerts');
    Schema::dropIfExists('mobility_alert_messages');
    Cache::store('array')->flush();
});

it('includes a confirmed strike during the report date plus seven day window with supported facts and source link', function (): void {
    morningStrike([
        'title' => 'Sciopero TRENORD',
        'starts_at' => '2026-10-24',
        'ends_at' => '2026-10-25',
        'district' => 'Como',
        'url' => 'https://scioperi.mit.gov.it/official-strike',
    ], [
        'operator' => 'TRENORD',
        'sector' => 'Ferroviario',
        'duration' => '06:00–09:00 e 18:00–21:00',
        'province' => 'Como',
        'notes' => 'Fasce garantite: 06:00–09:00 e 18:00–21:00',
    ]);

    $summary = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'como', 7);
    $text = app(MobilityStrikesSummaryFormatter::class)->formatMorningSection($summary);

    expect($summary['strikes'])->toHaveCount(1)
        ->and($text)->toContain('🚇 Забастовка железнодорожного транспорта · 24.10.2026–25.10.2026 · 06:00–09:00, 18:00–21:00')
        ->toContain('Возможны перебои в движении поездов.')
        ->not->toContain('Trenord', 'TRENORD', 'MIT', 'Источник:', 'Como', 'География:', 'Сфера:');
});

it('limits an ATM strike to Milan districts and excludes events beyond the seven day horizon', function (): void {
    morningStrike([
        'source' => 'atm',
        'title' => 'Sciopero ATM Milano 24/10/2026',
        'district' => 'Milano',
        'external_hash' => 'atm-milan',
        'strike_metadata' => null,
    ], []);
    morningStrike([
        'source' => 'atm',
        'title' => 'Sciopero ATM Milano 28/10/2026',
        'starts_at' => '2026-10-28',
        'ends_at' => '2026-10-28',
        'district' => 'Milano',
        'external_hash' => 'atm-outside-window',
        'strike_metadata' => null,
    ], []);

    $builder = app(MobilityStrikesSummaryBuilder::class);
    $formatter = app(MobilityStrikesSummaryFormatter::class);
    $milan = $formatter->formatMorningSection($builder->build('2026-10-20', 'navigli', 7));
    $como = $formatter->formatMorningSection($builder->build('2026-10-20', 'como', 7));

    expect($milan)->toContain('Забастовка городского транспорта · 24.10.2026')
        ->not->toContain('ATM')
        ->not->toContain('28/10/2026')
        ->and($como)->toBe('');
});

it('emphasizes tomorrow once within the seven day district overview', function (): void {
    morningStrike(['starts_at' => '2026-10-21', 'ends_at' => '2026-10-21']);

    $text = app(MobilityStrikesSummaryFormatter::class)->formatMorningSection(
        app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'navigli', 7)
    );

    expect($text)->toContain('21.10.2026')
        ->not->toContain('Завтра', 'ATM Milano', 'источник')
        ->and(substr_count($text, '21.10.2026'))->toBe(1);
});

it('does not show source diagnostics or cancelled announcements in the morning digest', function (): void {
    $formatter = app(MobilityStrikesSummaryFormatter::class);
    $summary = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'lodi', 7);

    expect($formatter->formatMorningSection($summary))->toBe('');

    morningStrike([], ['status' => 'cancelled']);
    $cancelled = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'navigli', 7);

    expect($formatter->formatMorningSection($cancelled))->toBe('');
});

it('omits duration text that is not a confirmed time range', function (): void {
    morningStrike([
        'title' => 'Sciopero GRUPPO ATM DI MILANO',
        'description' => 'Durata prevista per tutta la giornata',
    ], [
        'duration' => '24 ORE: VARIE MODALITA',
    ]);

    $text = app(MobilityStrikesSummaryFormatter::class)->formatMorningSection(
        app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'navigli', 7)
    );

    expect($text)->toContain('Забастовка городского транспорта · 24.10.2026')
        ->not->toContain('24 ORE', '06:00', '08:45', '15:00', 'ATM', 'MIT');
});

it('does not certify an empty strike overview from an empty successful HTML page', function (): void {
    config(['services.telegram.mobility_admin_targets' => null]);
    Http::fake(fn () => Http::response('<html><body></body></html>'));

    app(MobilityAlertSyncService::class)->sync();

    $status = app(MobilityStrikeSourceStatus::class)->forReport();
    expect($status['sources']['atm']['available'])->toBeFalse()
        ->and($status['sources']['trenord_news']['available'])->toBeFalse()
        ->and($status['sources']['telegram_undergroundstatus']['available'])->toBeFalse();
});

it('does not report a positive zero state when source completeness is unknown or partial', function (): void {
    $builder = app(MobilityStrikesSummaryBuilder::class);
    $formatter = app(MobilityStrikesSummaryFormatter::class);
    $unknownSummary = $builder->build('2026-10-20', 'lodi', 7);
    $unknown = $formatter->formatMorningSection($unknownSummary);
    $standalone = $formatter->format($unknownSummary);

    expect($unknown)->toBe('')
        ->not->toContain('Подтверждённых забастовок')
        ->and($standalone)->toContain('отсутствие забастовок не подтверждено')
        ->not->toContain('На сегодня подтверждённых забастовок');

    app(MobilityStrikeSourceStatus::class)->recordRun([
        'mit' => true,
        'atm' => true,
        'trenord_news' => false,
        'trenord_strikes' => false,
        'trenord_home' => true,
        'telegram_undergroundstatus' => true,
    ]);
    $partial = $formatter->formatMorningSection($builder->build('2026-10-20', 'lodi', 7));

    expect($partial)->toBe('');
});

it('reports a zero state only after all configured strike sources were recently available', function (): void {
    app(MobilityStrikeSourceStatus::class)->recordRun([
        'mit' => true,
        'atm' => true,
        'trenord_news' => true,
        'trenord_strikes' => true,
        'trenord_home' => true,
        'telegram_undergroundstatus' => true,
    ]);

    $text = app(MobilityStrikesSummaryFormatter::class)->formatMorningSection(
        app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'certosa', 7)
    );

    expect($text)->toBe('');
});

it('marks old source checks as stale instead of claiming that no strike exists', function (): void {
    app(MobilityStrikeSourceStatus::class)->recordRun([
        'mit' => true,
        'atm' => true,
        'trenord_news' => true,
        'trenord_strikes' => true,
        'trenord_home' => true,
        'telegram_undergroundstatus' => true,
    ], CarbonImmutable::now('Europe/Rome')->subHours(2));

    $text = app(MobilityStrikesSummaryFormatter::class)->formatMorningSection(
        app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'certosa', 7)
    );

    expect($text)->toBe('');
});

it('records unavailable providers from the existing mobility sync for the next morning report', function (): void {
    config(['services.telegram.mobility_admin_targets' => null]);
    $mit = '<table><tr><th>Inizio</th><th>Categoria</th></tr></table>';
    Http::fake(function ($request) use ($mit) {
        $url = $request->url();

        if ($url === config('mobility.strike_source_url')) {
            return Http::response($mit);
        }

        if (str_contains($url, 'trenord.it')) {
            return Http::response('', 403);
        }

        return Http::response('<html></html>');
    });

    app(MobilityAlertSyncService::class)->sync();

    $status = app(MobilityStrikeSourceStatus::class)->forReport();

    expect($status['state'])->toBe('partial')
        ->and(collect($status['unavailable'])->contains(fn (string $label): bool => str_contains($label, 'Trenord')))->toBeTrue();
});

it('keeps the existing mobility sync successful when source status cache storage is unavailable', function (): void {
    config([
        'cache.default' => 'database',
        'services.telegram.mobility_admin_targets' => null,
    ]);
    $mit = '<table><tr><th>Inizio</th><th>Categoria</th></tr></table>';
    Http::fake(fn () => Http::response($mit));

    expect(app(MobilityAlertSyncService::class)->sync())->toBe(0)
        ->and(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-20', 'como', 7)['source_status']['state'])->toBe('unknown');
});

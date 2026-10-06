<?php

use App\Models\MobilityAlert;
use App\Services\Mobility\MobilityStrikesSummaryBuilder;
use App\Services\Mobility\MobilityStrikesSummaryFormatter;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

function strikeAlert(array $attributes = [], array $metadata = []): MobilityAlert
{
    $id = (string) ($attributes['external_hash'] ?? uniqid('strike-', true));

    return MobilityAlert::create(array_replace([
        'source' => 'mit',
        'title' => 'Sciopero GRUPPO ATM DI MILANO',
        'type' => 'strike',
        'risk' => 'high',
        'district' => 'Lombardia',
        'starts_at' => '2026-10-07',
        'ends_at' => '2026-10-07',
        'external_hash' => $id,
        'strike_metadata' => array_replace([
            'identity' => $id,
            'status' => 'scheduled',
            'sector' => 'Trasporto pubblico locale',
            'operator' => 'GRUPPO ATM DI MILANO',
            'duration' => '',
            'scope' => 'Regionale',
            'notes' => '',
            'region' => 'Lombardia',
            'province' => 'Milano',
        ], $metadata),
    ], $attributes));
}

beforeEach(function (): void {
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
});

afterEach(function (): void {
    Schema::dropIfExists('mobility_alerts');
});

it('renders the requested date and a positive zero state without sending Telegram messages', function (): void {
    $this->mock(TelegramBotService::class)->shouldNotReceive('sendMessage', 'sendAnalyticsMessage');

    expect(Artisan::call('mobility:strikes-preview', ['--date' => '2026-10-07']))->toBe(0);
    expect(Artisan::output())->toContain('✊ TRIS — Забастовки · 07.10.2026')
        ->toContain('На сегодня подтверждённых забастовок, влияющих на работу TRIS, не найдено.');
});

it('shows a relevant ATM strike with its exact known hours and official source', function (): void {
    strikeAlert(metadata: ['duration' => '08:45–15:00 и после 18:00']);

    $text = app(MobilityStrikesSummaryFormatter::class)
        ->format(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07'));

    expect($text)->toContain('⚠️ ATM Milano', '08:45–15:00 и после 18:00')
        ->toContain('Возможны перебои в работе общественного транспорта.')
        ->toContain('Источник: MIT');
});

it('shows multiple relevant operators and preserves guaranteed windows only when supplied', function (): void {
    strikeAlert(['external_hash' => 'atm'], ['duration' => '08:45–15:00']);
    strikeAlert([
        'external_hash' => 'trenord',
        'title' => 'Sciopero TRENORD',
    ], [
        'operator' => 'TRENORD',
        'sector' => 'Ferroviario',
        'duration' => '',
        'notes' => 'Fasce garantite: 06:00–09:00 e 18:00–21:00',
    ]);

    $text = app(MobilityStrikesSummaryFormatter::class)
        ->format(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07'));

    expect($text)->toContain('ATM Milano', 'Trenord', '06:00–09:00 e 18:00–21:00')
        ->not->toContain('Время: 06:00');
});

it('excludes unrelated national and distant local strikes', function (): void {
    strikeAlert(['external_hash' => 'national', 'title' => 'Sciopero generale'], [
        'operator' => 'Scuole', 'sector' => 'Generale', 'scope' => 'Nazionale',
        'region' => 'Italia', 'province' => '',
    ]);
    strikeAlert(['external_hash' => 'bari'], [
        'operator' => 'AMTAB BARI', 'region' => 'Puglia', 'province' => 'Bari', 'scope' => 'Locale',
    ]);

    expect(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07')['strikes'])->toBe([]);
});

it('labels a revoked strike as cancelled rather than active', function (): void {
    strikeAlert(metadata: ['status' => 'cancelled', 'notes' => 'SCIOPERO REVOCATO', 'duration' => '08:45–15:00']);

    $text = app(MobilityStrikesSummaryFormatter::class)
        ->format(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07'));

    expect($text)->toContain('✅ ATM Milano — забастовка отменена.', 'Действующих забастовок не найдено.')
        ->not->toContain('⚠️ ATM Milano', 'Время: 08:45');
});

it('marks a persisted strike revision as updated', function (): void {
    strikeAlert(metadata: ['notification_kind' => 'updated']);

    $text = app(MobilityStrikesSummaryFormatter::class)
        ->format(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07'));

    expect($text)->toContain('⚠️ ATM Milano — данные обновлены.');
});

it('collapses a matching provider notice into the one MIT event', function (): void {
    strikeAlert([
        'source' => 'atm', 'title' => 'Sciopero ATM Milano 07/10/2026 08:45–15:00',
        'external_hash' => 'atm-provider', 'strike_metadata' => null,
    ]);
    strikeAlert(['external_hash' => 'mit-event'], ['duration' => '08:45–15:00']);

    $summary = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07');
    $text = app(MobilityStrikesSummaryFormatter::class)->format($summary);

    expect($summary['strikes'])->toHaveCount(1)
        ->and($text)->toContain('Источник: MIT, ATM Milano')
        ->and(substr_count($text, '⚠️ ATM Milano'))->toBe(1);
});

it('does not promote an undated generic provider link into a confirmed strike', function (): void {
    strikeAlert([
        'source' => 'trenord', 'title' => 'In caso di sciopero',
        'external_hash' => 'generic-page', 'strike_metadata' => null,
    ]);

    expect(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07')['strikes'])->toBe([]);
});

it('does not invent strike hours or reuse a provider title from the wrong year', function (): void {
    strikeAlert(['external_hash' => 'without-hours']);
    strikeAlert([
        'source' => 'atm', 'title' => 'Sciopero ATM Milano 07/10/2025',
        'external_hash' => 'wrong-year', 'strike_metadata' => null,
    ]);

    $summary = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07');
    $text = app(MobilityStrikesSummaryFormatter::class)->format($summary);

    expect($summary['strikes'])->toHaveCount(1)
        ->and($text)->not->toContain('Время:', '07/10/2025');
});

it('uses the historical requested date without changing persisted Mobility rows', function (): void {
    strikeAlert(['external_hash' => 'oct-seven'], ['duration' => '08:45–15:00']);
    strikeAlert(['external_hash' => 'oct-eight', 'starts_at' => '2026-10-08', 'ends_at' => '2026-10-08']);
    $before = MobilityAlert::query()->orderBy('id')->get()->map->getAttributes()->all();

    expect(Artisan::call('mobility:strikes-preview', ['--date' => '2026-10-07']))->toBe(0);
    expect(Artisan::output())->toContain('07.10.2026', '08:45–15:00')->not->toContain('08.10.2026');
    expect(MobilityAlert::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

it('keeps a crowded preview compact', function (): void {
    foreach (range(1, 7) as $number) {
        strikeAlert(['external_hash' => 'event-'.$number], [
            'operator' => 'TRASPORTO PUBBLICO LOCALE '.$number,
            'identity' => 'event-'.$number,
        ]);
    }

    $text = app(MobilityStrikesSummaryFormatter::class)
        ->format(app(MobilityStrikesSummaryBuilder::class)->build('2026-10-07'));

    expect($text)->toContain('Ещё 1 подтверждённых сообщений')
        ->and(mb_strlen($text))->toBeLessThan(4096);
});

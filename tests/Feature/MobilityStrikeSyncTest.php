<?php

use App\Jobs\DeliverMobilityAlert;
use App\Models\MobilityAlert;
use App\Models\MobilityAlertMessage;
use App\Services\Mobility\MitStrikeSource;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Mobility\MobilityStrikeSyncService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function mitStrikeTable(array $overrides = [], bool $duplicate = false): string
{
    $fields = array_replace([
        'ID' => 'fixture-strike-1', 'Inizio' => '09/10/2026', 'Fine' => '09/10/2026',
        'Sindacati' => 'AL-COBAS', 'Settore*' => 'Trasporto pubblico locale',
        'Categoria' => 'PERSONALE SOCC. GRUPPO ATM DI MILANO', 'Modalità' => '24 ORE: VARIE MODALITA',
        'Rilevanza' => 'Provinciale', 'Note' => '', 'Data proclamazione' => '27/07/2026',
        'Regione' => 'Lombardia', 'Provincia' => 'Tutte', 'Data ricezione' => '27/07/2026 15:30',
    ], $overrides);
    $headers = '<tr>'.implode('', array_map(fn ($key) => '<th>'.e($key).'</th>', array_keys($fields))).'</tr>';
    $row = '<tr>'.implode('', array_map(fn ($value) => '<td>'.e($value).'</td>', $fields)).'</tr>';

    return '<table>'.$headers.$row.($duplicate ? $row : '').'</table>';
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'Europe/Rome'));
    config(['services.telegram.mobility_admin_targets' => '-100123:17', 'services.telegram.analytics_bot_token' => null, 'queue.connections.database.connection' => null]);
    foreach (['2026_05_27_174319_create_mobility_alerts_table.php', '2026_07_02_042735_create_mobility_alert_messages_table.php', '2026_10_01_010000_add_strike_delivery_tracking_to_mobility_tables.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    Bus::fake([DeliverMobilityAlert::class]);
    Http::fake(['*' => Http::response(mitStrikeTable())]);
});

afterEach(function () {
    Schema::dropIfExists('mobility_alert_messages');
    Schema::dropIfExists('mobility_alerts');
    $this->travelBack();
});

it('queues a future ATM strike on discovery rather than its occurrence day', function () {
    $counts = app(MobilityStrikeSyncService::class)->sync();
    expect($counts['new'])->toBe(1)->and($counts['queued'])->toBe(1);
    expect(MobilityAlert::sole()->starts_at->toDateString())->toBe('2026-10-09');
    expect(MobilityAlertMessage::sole()->sent_at)->toBeNull();
    Bus::assertDispatched(DeliverMobilityAlert::class, fn ($job) => $job->connection === 'database' && $job->queue === 'default');
});

it('queues mobility delivery after commit when the database queue uses a separate connection', function () {
    config(['queue.connections.database.connection' => 'mysql']);

    $counts = app(MobilityStrikeSyncService::class)->sync();

    expect($counts['queued'])->toBe(1)
        ->and($counts['failed'])->toBe(0);
    Bus::assertDispatched(DeliverMobilityAlert::class, fn ($job): bool => $job->connection === 'database'
        && $job->queue === 'default'
        && $job->afterCommit === true
    );
});

it('does not queue unchanged records again including source reception changes', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Data ricezione' => '01/10/2026 10:00']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['unchanged'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(1);
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 1);
});

it('includes future regional Lombardia railway strikes', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(['Settore*' => 'Ferroviario', 'Categoria' => 'TRENORD', 'Rilevanza' => 'Regionale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('excludes a distant local strike', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(['Categoria' => 'AMTAB DI BARI', 'Regione' => 'Puglia', 'Provincia' => 'Bari', 'Rilevanza' => 'Locale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['relevant'])->toBe(0);
    expect(MobilityAlert::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('includes national transport strikes without Lombardia metadata', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(['Settore*' => 'Ferroviario', 'Categoria' => 'FERROVIE', 'Rilevanza' => 'Nazionale', 'Regione' => 'Italia']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('reserves exactly one notification for a meaningful update', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Inizio' => '10/10/2026', 'Fine' => '10/10/2026', 'Modalità' => '4 ORE']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(2);
    expect(MobilityAlertMessage::latest('id')->first()->text)->toContain('Обновление забастовки', 'Прежняя дата', '4 ORE');
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 2);
});

it('reserves an explicit cancellation once without inferring disappearance', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Note' => 'SCIOPERO REVOCATO']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['cancelled'])->toBe(1);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlertMessage::count())->toBe(2);
    expect(MobilityAlertMessage::latest('id')->first()->text)->toContain('Забастовка отменена');
});

it('deduplicates repeated source rows', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(duplicate: true))]);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(1);
});

it('leaves records and deliveries unchanged when the source fails', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $before = MobilityAlert::sole()->getAttributes();
    Http::fake(['*' => Http::response('', 503)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(1);
    expect(MobilityAlert::sole()->getAttributes())->toBe($before);
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 1);
});

it('isolates a malformed item while processing valid items', function () {
    $html = mitStrikeTable(['ID' => 'bad', 'Inizio' => '32/10/2026']).mitStrikeTable();
    Http::fake(['*' => Http::response($html)]);
    $counts = app(MobilityStrikeSyncService::class)->sync();
    expect($counts['failed'])->toBe(1)->and($counts['queued'])->toBe(1);
});

it('does not mark a failed Telegram delivery successful', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnNull();
    expect(fn () => (new DeliverMobilityAlert(MobilityAlertMessage::sole()->id))->handle($bot))->toThrow(RuntimeException::class);
    expect(MobilityAlertMessage::sole()->sent_at)->toBeNull();
});

it('records confirmed delivery and makes duplicate jobs inert', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $job = new DeliverMobilityAlert(MobilityAlertMessage::sole()->id);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturn(321);
    $job->handle($bot);
    $job->handle($bot);
    expect(MobilityAlertMessage::sole()->telegram_message_id)->toBe('321');
    expect(MobilityAlertMessage::sole()->sent_at)->not->toBeNull();
});

it('keeps dry run read only while reporting future discovery', function () {
    expect(Artisan::call('mobility:sync', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('"new":1');
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
    Http::assertSentCount(1);
});

it('parses Rome dates independently of application timezone', function () {
    config(['app.timezone' => 'Asia/Yekaterinburg']);
    $items = app(MitStrikeSource::class)->normalize(mitStrikeTable());
    expect($items['items'][0]['starts_at'])->toBe('2026-10-09');
    $dateMethod = new ReflectionMethod(MitStrikeSource::class, 'date');
    expect($dateMethod->invoke(app(MitStrikeSource::class), '09/10/2026')->timezoneName)->toBe('Europe/Rome');
});

it('runs the sync hourly with overlap protection', function () {
    $events = app(Schedule::class)->events();
    $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'mobility:sync'));
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('rolls back alert and reservation when queue insertion fails', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    Bus::swap($dispatcher);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(1);
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
});

it('recovers abandoned unsent reservations without marking them sent', function () {
    app(MobilityStrikeSyncService::class)->sync();
    MobilityAlertMessage::sole()->update(['queued_at' => now()->subHours(2)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(1)->and(MobilityAlertMessage::sole()->sent_at)->toBeNull();
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 2);
});

it('does not manufacture cancellation from an empty recognized registry', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $empty = preg_replace('/<tr><td>.*?<\/tr>/s', '', mitStrikeTable());
    Http::fake(['*' => Http::response($empty)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['cancelled'])->toBe(0);
    expect(MobilityAlert::sole()->strike_metadata['status'])->toBe('scheduled');
});

it('reports already delivered reservations without dry run writes', function () {
    app(MobilityStrikeSyncService::class)->sync();
    MobilityAlertMessage::sole()->update(['sent_at' => now(), 'telegram_message_id' => '321']);
    $alert = MobilityAlert::sole()->getAttributes();
    $message = MobilityAlertMessage::sole()->getAttributes();
    $counts = app(MobilityStrikeSyncService::class)->sync(dryRun: true);
    expect($counts['already_notified'])->toBe(1)->and($counts['would_queue'])->toBe(0);
    expect(MobilityAlert::sole()->getAttributes())->toBe($alert);
    expect(MobilityAlertMessage::sole()->getAttributes())->toBe($message);
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 1);
});

it('uses stable fallback identity for the actual public table without IDs', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(['ID' => '']))]);
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['ID' => '', 'Modalità' => '4 ORE']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    expect(MobilityAlert::count())->toBe(1);
});

it('normalizes union ordering and whitespace for identity and version', function () {
    Http::fake(['*' => Http::response(mitStrikeTable(['ID' => '', 'Sindacati' => 'CUB/USB']))]);
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['ID' => '', 'Sindacati' => ' USB / CUB ']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['unchanged'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(1);
});

it('notifies a meaningful reversion as a new revision', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Modalità' => '4 ORE']))]);
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable())]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(3);
});

it('isolates structured sector and geography from unrelated text', function () {
    $source = app(MitStrikeSource::class);
    $item = $source->normalize(mitStrikeTable(['Regione' => 'Puglia', 'Provincia' => 'Bari', 'Categoria' => 'ATM DI MILANO']))['items'][0];
    expect($source->relevant($item))->toBeFalse();
    $item['region'] = '';
    $item['province'] = '';
    expect($source->relevant($item))->toBeTrue();
    $item['sector'] = 'Aereo';
    expect($source->relevant($item))->toBeFalse();
});

it('retains pending delivery after a transport exception without exposing its text', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andThrow(new RuntimeException('private transport detail'));
    expect(fn () => (new DeliverMobilityAlert(MobilityAlertMessage::sole()->id))->handle($bot))
        ->toThrow(RuntimeException::class, 'Mobility Telegram transport failed.');
    expect(MobilityAlertMessage::sole()->sent_at)->toBeNull();
});

it('rejects an unrecognized successful source page without changing the ledger', function () {
    Http::fake(['*' => Http::response('<html>Maintenance</html>')]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(1);
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('queues operator and scope amendments under the official ID', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Categoria' => 'ATM MILANO - RETE METRO', 'Rilevanza' => 'Regionale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(2);
});

it('keeps normal delivery available when the destination is configured later', function () {
    config(['services.telegram.mobility_admin_targets' => null]);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(0);
    config(['services.telegram.mobility_admin_targets' => '-100123:17', 'services.telegram.analytics_bot_token' => null, 'queue.connections.database.connection' => null]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('reports source failure through the existing sync command', function () {
    Http::fake(['*' => Http::response('', 503)]);
    expect(Artisan::call('mobility:sync'))->toBe(1);
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('omits explicitly cancelled strikes from existing mobility read sets without deleting history', function () {
    app(MobilityStrikeSyncService::class)->sync();
    Http::fake(['*' => Http::response(mitStrikeTable(['Note' => 'SCIOPERO REVOCATO']))]);
    app(MobilityStrikeSyncService::class)->sync();
    $visible = app(MobilityAlertSyncService::class)->filterRepresentedRawAlerts(MobilityAlert::all());
    expect($visible)->toHaveCount(0)->and(MobilityAlert::count())->toBe(1);
});

it('does not choose between conflicting ID-less rows by source ordering', function () {
    $first = mitStrikeTable(['ID' => '']);
    $second = mitStrikeTable(['ID' => '', 'Inizio' => '10/10/2026', 'Fine' => '10/10/2026']);
    $source = app(MitStrikeSource::class);
    expect($source->normalize($first.$second)['items'])->toBe([]);
    expect($source->normalize($second.$first)['items'])->toBe([]);
    Http::fake(['*' => Http::response($first.$second)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(2);
    expect(MobilityAlert::count())->toBe(0);
    Bus::assertNothingDispatched();
});

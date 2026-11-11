<?php

use App\Jobs\DeliverMobilityAlert;
use App\Models\MobilityAlert;
use App\Models\MobilityAlertMessage;
use App\Services\Mobility\MitStrikeSource;
use App\Services\Mobility\MobilityAlertSyncService;
use App\Services\Mobility\MobilityStrikesSummaryBuilder;
use App\Services\Mobility\MobilityStrikesSummaryFormatter;
use App\Services\Mobility\MobilityStrikeSyncService;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

function fakeMitHttp(array|callable $mitResponse): void
{
    Http::swap(new Factory);
    Http::fake(function ($request) use ($mitResponse) {
        $url = $request->url();
        if ($url === config('mobility.strike_source_url')) {
            if (is_callable($mitResponse)) {
                return $mitResponse($request);
            }
            foreach ($mitResponse as $pattern => $response) {
                if (Str::is($pattern, $url)) {
                    return $response;
                }
            }

            return Http::response('', 404);
        }
        if (str_contains($url, 't.me/s/undergroundstatus')) {
            return Http::response('<div class="tgme_channel_info">Metro status</div>');
        }

        $brand = str_contains($url, 'atm.it') ? 'ATM' : 'Trenord';

        return Http::response('<html><body>'.$brand.'</body></html>');
    });
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['mobility.strike_notifications_enabled' => true, 'mobility.strike_notifications_active_since' => '2026-07-01']);
    $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'Europe/Rome'));
    config(['services.telegram.mobility_admin_targets' => '-100123:17', 'services.telegram.mobility_strike_chat_id' => '-100123', 'services.telegram.mobility_strike_thread_id' => '17', 'services.telegram.analytics_bot_token' => null, 'queue.connections.database.connection' => null]);
    foreach (['2026_05_27_174319_create_mobility_alerts_table.php', '2026_07_02_042735_create_mobility_alert_messages_table.php', '2026_10_01_010000_add_strike_delivery_tracking_to_mobility_tables.php', '2026_10_29_000000_add_delivery_attempt_state_to_mobility_alert_messages.php'] as $file) {
        (require dirname(__DIR__, 2).'/database/migrations/'.$file)->up();
    }
    Bus::fake([DeliverMobilityAlert::class]);
    fakeMitHttp(['*' => Http::response(mitStrikeTable())]);
});

it('stores historical first discoveries without sending a backlog and still notifies a later amendment', function () {
    config(['mobility.strike_notifications_active_since' => '2026-10-01']);
    $source = Mockery::mock(MitStrikeSource::class);
    $source->shouldReceive('relevant')->twice()->andReturnTrue();
    $source->shouldReceive('fetch')->twice()->andReturn(
        app(MitStrikeSource::class)->normalize(mitStrikeTable()),
        app(MitStrikeSource::class)->normalize(mitStrikeTable(['Modalità' => '4 ORE'])),
    );
    $sync = new MobilityStrikeSyncService($source);

    expect($sync->sync()['queued'])->toBe(0)
        ->and(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(0);
    expect($sync->sync()['queued'])->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(1);
});

it('does not reserve strike notifications while activation is disabled', function () {
    config(['mobility.strike_notifications_enabled' => false]);

    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(0)
        ->and(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(0);
});

it('tracks official operator notices once and sends a later published postponement through the existing ledger', function (): void {
    $sync = app(MobilityStrikeSyncService::class);
    $event = [
        'source' => 'atm',
        'title' => 'Sciopero ATM del 30 ottobre 2026',
        'description' => 'Sciopero USB confermato.',
        'url' => 'https://www.atm.it/it/ViaggiaConNoi/InfoTraffico/Pagine/Sciopero.aspx',
        'type' => 'strike',
        'risk' => 'high',
        'district' => 'Milano',
        'starts_at' => '2026-10-30',
        'ends_at' => '2026-10-30',
        'metadata' => [
            'status' => 'scheduled', 'operator' => 'ATM Milano', 'sector' => 'Trasporto pubblico locale',
            'region' => 'Lombardia', 'scope' => 'Regionale', 'publication_date' => '2026-10-01',
            'duration' => '08:00–20:00', 'notes' => 'USB',
        ],
    ];

    expect($sync->syncExternalNotice($event))->toBe(1)
        ->and($sync->syncExternalNotice($event))->toBe(0)
        ->and(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(1);

    $event['title'] = 'Sciopero ATM del 30 ottobre 2026 rinviato';
    $event['description'] = 'Con ordinanza il Ministero ha rinviato lo sciopero.';
    $event['starts_at'] = null;
    $event['ends_at'] = null;
    $event['metadata']['status'] = 'postponed';
    $event['metadata']['duration'] = null;
    $event['metadata']['notes'] = 'Nuova data non confermata.';

    $sync->syncExternalNotice($event);
    $sync->syncExternalNotice($event);

    expect(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlert::sole()->strike_metadata['status'])->toBe('postponed')
        ->and(MobilityAlertMessage::count())->toBe(2)
        ->and(MobilityAlertMessage::latest('id')->first()->text)->toContain('перенесена', 'не подтверждена', 'https://www.atm.it/');
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 2);
});

it('does not send an existing operator notice on the first activated sync when its publication predates activation', function (): void {
    config(['mobility.strike_notifications_active_since' => '2026-10-01']);
    $event = [
        'source' => 'trenord', 'title' => 'Sciopero Trenord', 'description' => 'Sciopero ferroviario.',
        'url' => 'https://www.trenord.it/news/trenord-informa/avvisi/sciopero/', 'type' => 'strike', 'risk' => 'high',
        'district' => 'Lombardia', 'starts_at' => '2026-10-30', 'ends_at' => '2026-10-30',
        'metadata' => ['status' => 'scheduled', 'operator' => 'Trenord', 'sector' => 'Ferroviario', 'publication_date' => '2026-09-30'],
    ];

    app(MobilityStrikeSyncService::class)->syncExternalNotice($event);

    expect(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('reserves one version for a newly announced strike, its move and its cancellation', function () {
    config(['mobility.strike_notifications_active_since' => '2026-10-01']);
    $source = Mockery::mock(MitStrikeSource::class);
    $source->shouldReceive('relevant')->times(4)->andReturnTrue();
    $source->shouldReceive('fetch')->times(4)->andReturn(
        app(MitStrikeSource::class)->normalize(mitStrikeTable(['Data proclamazione' => '01/10/2026'])),
        app(MitStrikeSource::class)->normalize(mitStrikeTable(['Data proclamazione' => '01/10/2026', 'Inizio' => '10/10/2026', 'Fine' => '10/10/2026'])),
        app(MitStrikeSource::class)->normalize(mitStrikeTable(['Data proclamazione' => '01/10/2026', 'Inizio' => '10/10/2026', 'Fine' => '10/10/2026', 'Note' => 'SCIOPERO REVOCATO'])),
        app(MitStrikeSource::class)->normalize(mitStrikeTable(['Data proclamazione' => '01/10/2026', 'Inizio' => '10/10/2026', 'Fine' => '10/10/2026', 'Note' => 'SCIOPERO REVOCATO'])),
    );
    $sync = new MobilityStrikeSyncService($source);

    expect($sync->sync()['queued'])->toBe(1)
        ->and($sync->sync()['queued'])->toBe(1)
        ->and($sync->sync()['queued'])->toBe(1)
        ->and($sync->sync()['queued'])->toBe(0)
        ->and(MobilityAlert::count())->toBe(1)
        ->and(MobilityAlertMessage::count())->toBe(3)
        ->and(MobilityAlertMessage::query()->distinct()->pluck('chat_id')->all())->toBe(['-100123'])
        ->and(MobilityAlertMessage::query()->distinct()->pluck('thread_id')->all())->toBe(['17'])
        ->and(MobilityAlertMessage::latest('id')->first()->text)->toContain('Забастовка отменена');
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

it('does not publish a postponed MIT event as an active strike', function (): void {
    fakeMitHttp(['*' => Http::response(mitStrikeTable([
        'Inizio' => '30/10/2026',
        'Fine' => '30/10/2026',
        'Note' => 'Sciopero rinviato dal Ministero.',
    ]))]);

    app(MobilityStrikeSyncService::class)->sync();

    $summary = app(MobilityStrikesSummaryBuilder::class)->build('2026-10-30');

    expect(MobilityAlert::sole()->strike_metadata['status'])->toBe('postponed')
        ->and($summary['strikes'][0]['status'])->toBe('postponed')
        ->and(app(MobilityStrikesSummaryFormatter::class)->formatMorningSection($summary))->toBe('');
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
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Data ricezione' => '01/10/2026 10:00']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['unchanged'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(1);
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 1);
});

it('includes future regional Lombardia railway strikes', function () {
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Settore*' => 'Ferroviario', 'Categoria' => 'TRENORD', 'Rilevanza' => 'Regionale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('excludes a distant local strike', function () {
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Categoria' => 'AMTAB DI BARI', 'Regione' => 'Puglia', 'Provincia' => 'Bari', 'Rilevanza' => 'Locale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['relevant'])->toBe(0);
    expect(MobilityAlert::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('includes national transport strikes without Lombardia metadata', function () {
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Settore*' => 'Ferroviario', 'Categoria' => 'FERROVIE', 'Rilevanza' => 'Nazionale', 'Regione' => 'Italia']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('reserves exactly one notification for a meaningful update', function () {
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Inizio' => '10/10/2026', 'Fine' => '10/10/2026', 'Modalità' => '4 ORE']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(2);
    expect(MobilityAlertMessage::latest('id')->first()->text)->toContain('Обновление забастовки', 'Прежняя дата', '4 ORE');
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 2);
});

it('reserves an explicit cancellation once without inferring disappearance', function () {
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Note' => 'SCIOPERO REVOCATO']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['cancelled'])->toBe(1);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlertMessage::count())->toBe(2);
    expect(MobilityAlertMessage::latest('id')->first()->text)->toContain('Забастовка отменена');
});

it('deduplicates repeated source rows', function () {
    fakeMitHttp(['*' => Http::response(mitStrikeTable(duplicate: true))]);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(1);
});

it('leaves records and deliveries unchanged when the source fails', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $before = MobilityAlert::sole()->getAttributes();
    fakeMitHttp(['*' => Http::response('', 503)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(1);
    expect(MobilityAlert::sole()->getAttributes())->toBe($before);
    Bus::assertDispatchedTimes(DeliverMobilityAlert::class, 1);
});

it('isolates a malformed item while processing valid items', function () {
    $html = mitStrikeTable(['ID' => 'bad', 'Inizio' => '32/10/2026']).mitStrikeTable();
    fakeMitHttp(['*' => Http::response($html)]);
    $counts = app(MobilityStrikeSyncService::class)->sync();
    expect($counts['failed'])->toBe(1)->and($counts['queued'])->toBe(1);
});

it('records uncertain Telegram delivery without marking it sent or retrying blindly', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnNull();
    $job = new DeliverMobilityAlert(MobilityAlertMessage::sole()->id);
    $job->handle($bot);
    $job->handle($bot);

    expect(MobilityAlertMessage::sole()->sent_at)->toBeNull()
        ->and(MobilityAlertMessage::sole()->delivery_status)->toBe('uncertain')
        ->and(MobilityAlertMessage::sole()->error_code)->toBe('telegram_receipt_missing');
});

it('calls Telegram outside the database transaction after persisting the delivery claim', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $message = MobilityAlertMessage::sole();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andReturnUsing(function () use ($message): int {
        expect(DB::transactionLevel())->toBe(0)
            ->and(MobilityAlertMessage::find($message->id)->delivery_status)->toBe('sending');

        return 987;
    });

    (new DeliverMobilityAlert($message->id))->handle($bot);

    expect($message->fresh()->delivery_status)->toBe('sent')
        ->and($message->fresh()->telegram_message_id)->toBe('987')
        ->and($message->fresh()->attempted_at)->not->toBeNull();
});

it('marks a delivery uncertain after an interrupted in-flight attempt without another send', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $message = MobilityAlertMessage::sole();
    $message->forceFill(['delivery_status' => 'sending', 'attempted_at' => now()->subMinutes(3)])->save();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldNotReceive('sendAnalyticsMessage');

    (new DeliverMobilityAlert($message->id))->handle($bot);

    expect($message->fresh()->delivery_status)->toBe('uncertain')
        ->and($message->fresh()->error_code)->toBe('worker_interrupted_during_send');
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

it('keeps dry run read only while checking every configured source', function () {
    Http::swap(new Factory);
    Http::fake(function ($request) {
        if ($request->url() === config('mobility.strike_source_url')) {
            return Http::response(mitStrikeTable());
        }
        if (str_contains($request->url(), 't.me/s/undergroundstatus')) {
            return Http::response('<div class="tgme_channel_info">Metro status</div>');
        }

        $brand = str_contains($request->url(), 'atm.it') ? 'ATM' : 'Trenord';

        return Http::response('<html><body>'.$brand.'<a href="/info">Informazioni aggiornate</a></body></html>');
    });

    $exitCode = Artisan::call('mobility:sync', ['--dry-run' => true]);
    $output = Artisan::output();
    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('"new":1', '"atm"', '"trenord_news"', '"trenord_strikes"', '"trenord_home"', '"checked_empty"');
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
    Http::assertSentCount(6);
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
    fakeMitHttp(['*' => Http::response($empty)]);
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
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['ID' => '']))]);
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['ID' => '', 'Modalità' => '4 ORE']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    expect(MobilityAlert::count())->toBe(1);
});

it('normalizes union ordering and whitespace for identity and version', function () {
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['ID' => '', 'Sindacati' => 'CUB/USB']))]);
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['ID' => '', 'Sindacati' => ' USB / CUB ']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['unchanged'])->toBe(1);
    expect(MobilityAlertMessage::count())->toBe(1);
});

it('notifies a meaningful reversion as a new revision', function () {
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Modalità' => '4 ORE']))]);
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable())]);
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

it('records an uncertain transport result without exposing details or retrying blindly', function () {
    app(MobilityStrikeSyncService::class)->sync();
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendAnalyticsMessage')->once()->andThrow(new RuntimeException('private transport detail'));
    $job = new DeliverMobilityAlert(MobilityAlertMessage::sole()->id);
    $job->handle($bot);
    $job->handle($bot);

    expect(MobilityAlertMessage::sole()->sent_at)->toBeNull()
        ->and(MobilityAlertMessage::sole()->delivery_status)->toBe('uncertain')
        ->and(MobilityAlertMessage::sole()->error_code)->toBe('RuntimeException');
});

it('rejects an unrecognized successful source page without changing the ledger', function () {
    fakeMitHttp(['*' => Http::response('<html>Maintenance</html>')]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(1);
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('queues operator and scope amendments under the official ID', function () {
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Categoria' => 'ATM MILANO - RETE METRO', 'Rilevanza' => 'Regionale']))]);
    expect(app(MobilityStrikeSyncService::class)->sync()['updated'])->toBe(1);
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(2);
});

it('keeps normal delivery available when the destination is configured later', function () {
    config(['services.telegram.mobility_strike_chat_id' => null]);
    app(MobilityStrikeSyncService::class)->sync();
    expect(MobilityAlert::count())->toBe(1)->and(MobilityAlertMessage::count())->toBe(0);
    config(['services.telegram.mobility_strike_chat_id' => '-100123', 'services.telegram.analytics_bot_token' => null, 'queue.connections.database.connection' => null]);
    expect(app(MobilityStrikeSyncService::class)->sync()['queued'])->toBe(1);
});

it('reports source failure through the existing sync command', function () {
    fakeMitHttp(['*' => Http::response('', 503)]);
    expect(Artisan::call('mobility:sync'))->toBe(1);
    expect(MobilityAlert::count())->toBe(0)->and(MobilityAlertMessage::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('omits explicitly cancelled strikes from existing mobility read sets without deleting history', function () {
    app(MobilityStrikeSyncService::class)->sync();
    fakeMitHttp(['*' => Http::response(mitStrikeTable(['Note' => 'SCIOPERO REVOCATO']))]);
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
    fakeMitHttp(['*' => Http::response($first.$second)]);
    expect(app(MobilityStrikeSyncService::class)->sync()['failed'])->toBe(2);
    expect(MobilityAlert::count())->toBe(0);
    Bus::assertNothingDispatched();
});
